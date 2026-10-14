<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\IdempotencyConflictException;
use App\Actions\Poker\Exceptions\InsufficientStackException;
use App\Actions\Poker\Exceptions\InvalidActionException;
use App\Actions\Poker\Exceptions\InvalidBetAmountException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\Exceptions\PlayerNotInHandException;
use App\Events\PokerHandStateUpdated;
use App\Events\RoomStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\TableStack;
use App\Models\User;
use App\Support\PotCalculator;
use App\Support\TurnTimer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Player betting actions: check, call, bet, raise, fold, all_in.
 *
 * Conventions (PRD 8):
 * - `raise` uses `raise_to`: total street contribution after the action.
 * - Contributions move stack -> hand escrow with an auditable ledger row.
 * - Betting completion moves the hand to an `awaiting_*` state; the host
 *   opens the next street with AdvanceStreet after the physical cards
 *   are dealt.
 */
class PlayerAction
{
    /**
     * @return array<string, mixed>
     *
     * @throws PlayerNotInHandException
     * @throws InvalidHandStateException
     * @throws NotYourTurnException
     * @throws InvalidActionException
     * @throws InvalidBetAmountException
     * @throws InsufficientStackException
     * @throws IdempotencyConflictException
     */
    public function handle(
        User $player,
        PokerHand $hand,
        string $action,
        string $idempotencyKey,
        ?int $amount = null,
        ?int $raiseTo = null,
        bool $isTimeout = false,
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);

        $existing = PokerAction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertReplayMatches($existing, $player, $hand, $action, $amount, $raiseTo, $isTimeout);

            return $this->replay($existing);
        }

        try {
            $result = DB::transaction(function () use (
                $hand, $player, $action, $idempotencyKey, $amount, $raiseTo, $isTimeout
            ): array {
                // Consistent lock order: hand, players (by id), stacks (by id).
                $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedHand->status, [
                    PokerHand::STATUS_PREFLOP,
                    PokerHand::STATUS_FLOP,
                    PokerHand::STATUS_TURN,
                    PokerHand::STATUS_RIVER,
                ], true)) {
                    throw new InvalidHandStateException('Betting is not allowed on the current street.');
                }

                if ($lockedHand->current_turn_user_id !== $player->id) {
                    throw new NotYourTurnException('It is not your turn to act.');
                }

                $lockedPlayer = PokerHandPlayer::where('hand_id', $lockedHand->id)
                    ->where('user_id', $player->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedPlayer === null) {
                    throw new PlayerNotInHandException('You are not a participant in this hand.');
                }

                if ($lockedPlayer->status === PokerHandPlayer::STATUS_FOLDED) {
                    throw new InvalidActionException('You have already folded.');
                }

                if (in_array($lockedPlayer->status, [
                    PokerHandPlayer::STATUS_ALL_IN,
                    PokerHandPlayer::STATUS_OUT,
                ], true)) {
                    throw new InvalidActionException('You cannot act any further in this hand.');
                }

                // A player who already acted may only act again when facing
                // an unmatched bet (action was reopened by a raise).
                if ($lockedPlayer->acted_this_street
                    && $lockedPlayer->street_contribution >= $lockedHand->current_bet) {
                    throw new InvalidActionException('You have already acted this street.');
                }

                $lockedStack = TableStack::where('room_id', $lockedHand->room_id)
                    ->where('user_id', $player->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedStack === null) {
                    throw new InsufficientStackException('No table stack found for this hand.');
                }

                $currentBet = (int) $lockedHand->current_bet;
                $minFullRaise = (int) $lockedHand->min_full_raise;
                $streetContribution = (int) $lockedPlayer->street_contribution;
                $stackBalance = (int) $lockedStack->balance;

                $contribution = 0;
                $newStatus = $lockedPlayer->status;
                $isFullRaise = false;

                switch ($action) {
                    case 'check':
                        if ($streetContribution < $currentBet) {
                            throw new InvalidBetAmountException('You must call or raise. Checking is not allowed when there is a bet to match.');
                        }
                        break;

                    case 'call':
                        $needed = $currentBet - $streetContribution;
                        if ($needed <= 0) {
                            throw new InvalidBetAmountException('No bet to call. You may check instead.');
                        }
                        // Short call: remaining stack, becomes all-in.
                        $contribution = min($needed, $stackBalance);
                        if ($contribution <= 0) {
                            throw new InsufficientStackException('No chips left to call with.');
                        }
                        break;

                    case 'bet':
                        if ($currentBet > 0) {
                            throw new InvalidBetAmountException('Cannot bet when there is already a bet. Use raise instead.');
                        }
                        if ($amount === null || $amount <= 0) {
                            throw new InvalidBetAmountException('Bet amount must be a positive integer.');
                        }
                        $minBet = (int) $lockedHand->big_blind_amount;
                        $isAllInBet = $amount >= $stackBalance;
                        if ($amount < $minBet && ! $isAllInBet) {
                            throw new InvalidBetAmountException("Minimum bet is {$minBet} chips.");
                        }
                        if ($amount > $stackBalance) {
                            throw new InsufficientStackException('Insufficient stack for this bet.');
                        }
                        $contribution = $isAllInBet ? $stackBalance : $amount;
                        $isFullRaise = $contribution >= $minBet;
                        break;

                    case 'raise':
                        if ($currentBet === 0) {
                            throw new InvalidBetAmountException('Cannot raise when there is no bet. Use bet instead.');
                        }
                        if ($raiseTo === null || $raiseTo <= $currentBet) {
                            throw new InvalidBetAmountException('Raise target (raise_to) must exceed the current bet.');
                        }
                        $additional = $raiseTo - $streetContribution;
                        if ($additional <= 0) {
                            throw new InvalidBetAmountException('Raise target must exceed your current street contribution.');
                        }
                        if ($additional > $stackBalance) {
                            throw new InsufficientStackException('Insufficient stack for this raise.');
                        }
                        $isAllInRaise = $additional === $stackBalance;
                        $minRaiseTo = $currentBet + $minFullRaise;
                        if ($raiseTo < $minRaiseTo && ! $isAllInRaise) {
                            throw new InvalidBetAmountException("Minimum raise to is {$minRaiseTo} chips (current bet: {$currentBet}, min full raise: {$minFullRaise}).");
                        }
                        $contribution = $additional;
                        // Short all-in raise does NOT reopen action.
                        $isFullRaise = ($raiseTo - $currentBet) >= $minFullRaise;
                        break;

                    case 'fold':
                        $newStatus = PokerHandPlayer::STATUS_FOLDED;
                        break;

                    case 'all_in':
                        if ($stackBalance <= 0) {
                            throw new InsufficientStackException('No chips left to go all-in with.');
                        }
                        $contribution = $stackBalance;
                        $newStreetTotal = $streetContribution + $contribution;
                        if ($currentBet === 0) {
                            $isFullRaise = $newStreetTotal >= (int) $lockedHand->big_blind_amount;
                        } else {
                            $isFullRaise = ($newStreetTotal - $currentBet) >= $minFullRaise;
                        }
                        break;

                    default:
                        throw new InvalidActionException('Unknown action: '.$action);
                }

                $oldCurrentBet = $currentBet;

                if ($contribution > 0) {
                    $lockedStack->balance -= $contribution;
                    $lockedStack->save();

                    $lockedPlayer->street_contribution += $contribution;
                    $lockedPlayer->total_contribution += $contribution;
                    $lockedHand->total_pot += $contribution;

                    if ((int) $lockedStack->balance === 0 && $newStatus === PokerHandPlayer::STATUS_ACTIVE) {
                        $newStatus = PokerHandPlayer::STATUS_ALL_IN;
                    }

                    // Track the betting level. Short all-ins still set the
                    // price to match but never shrink the minimum full raise.
                    if ($lockedPlayer->street_contribution > $oldCurrentBet) {
                        $lockedHand->current_bet = $lockedPlayer->street_contribution;
                        if ($isFullRaise) {
                            $increment = $lockedPlayer->street_contribution - $oldCurrentBet;
                            $lockedHand->min_full_raise = max($minFullRaise, $increment);
                            $lockedHand->last_aggressor_user_id = $player->id;
                        }
                    }

                    $lockedHand->save();
                }

                $lockedPlayer->acted_this_street = true;
                $lockedPlayer->status = $newStatus;
                $lockedPlayer->save();

                $pokerAction = PokerAction::create([
                    'hand_id' => $lockedHand->id,
                    'user_id' => $player->id,
                    'street' => $lockedHand->street,
                    'action' => $action,
                    'amount' => $contribution,
                    'raise_to' => $action === 'raise' ? $raiseTo : null,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $this->buildActionMetadata($action, $contribution, $raiseTo, $isTimeout),
                ]);

                if ($contribution > 0) {
                    ChipTransaction::create([
                        'from_table_stack_id' => $lockedStack->id,
                        'to_poker_hand_id' => $lockedHand->id,
                        'from_hand_player_id' => $lockedPlayer->id,
                        'type' => $this->mapActionToTransactionType($action),
                        'amount' => $contribution,
                        'idempotency_key' => $idempotencyKey,
                        'metadata' => [
                            'hand_id' => $lockedHand->id,
                            'player_id' => $lockedPlayer->id,
                            'action' => $action,
                            'street' => $lockedHand->street,
                        ],
                    ]);
                }

                // Fold-win: only one player remains who has not folded.
                $remaining = PokerHandPlayer::where('hand_id', $lockedHand->id)
                    ->where('status', '!=', PokerHandPlayer::STATUS_FOLDED)
                    ->lockForUpdate()
                    ->get();

                if ($remaining->count() === 1) {
                    $this->settleFoldWin($lockedHand, $remaining->first(), $idempotencyKey);
                    TurnTimer::clear($lockedHand);

                    return [
                        'hand' => $lockedHand->refresh(),
                        'player' => $lockedPlayer->refresh(),
                        'action' => $pokerAction,
                        'street_complete' => true,
                        'fold_win' => true,
                    ];
                }

                if ($this->isBettingComplete($lockedHand)) {
                    $this->moveToAwaiting($lockedHand);
                    TurnTimer::clear($lockedHand);

                    return [
                        'hand' => $lockedHand->refresh(),
                        'player' => $lockedPlayer->refresh(),
                        'action' => $pokerAction,
                        'street_complete' => true,
                        'fold_win' => false,
                    ];
                }

                $next = $this->nextToAct($lockedHand);
                if ($next !== null) {
                    $lockedHand->current_turn_user_id = $next->user_id;
                    $lockedHand->save();
                }

                TurnTimer::arm($lockedHand, $lockedHand->current_turn_user_id);

                return [
                    'hand' => $lockedHand->refresh(),
                    'player' => $lockedPlayer->refresh(),
                    'action' => $pokerAction,
                    'street_complete' => false,
                    'fold_win' => false,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = PokerAction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $this->assertReplayMatches($existing, $player, $hand, $action, $amount, $raiseTo, $isTimeout);

                    return $this->replay($existing);
                }
            }

            throw $e;
        }

        PokerHandStateUpdated::dispatch($hand->id, 'action');
        RoomStateUpdated::dispatch($hand->room_id);

        return $result;
    }

    /**
     * Betting is complete when nobody who can still bet needs to act:
     * every ACTIVE player has acted and matched the current bet, or there
     * is at most one player still able to bet (rest all-in).
     */
    private function isBettingComplete(PokerHand $hand): bool
    {
        $players = PokerHandPlayer::where('hand_id', $hand->id)->get();
        $live = $players->where('status', '!=', PokerHandPlayer::STATUS_FOLDED);

        if ($live->count() <= 1) {
            return true;
        }

        $canBet = $players->where('status', PokerHandPlayer::STATUS_ACTIVE);

        if ($canBet->count() <= 1) {
            // Zero or one player can still bet: complete only when that
            // player's contribution matches (nothing left to decide).
            foreach ($canBet as $p) {
                if (! $p->acted_this_street || (int) $p->street_contribution !== (int) $hand->current_bet) {
                    // Single remaining bettor who still needs to match an
                    // outstanding bet must still act.
                    if ($canBet->count() === 1 && (int) $p->street_contribution < (int) $hand->current_bet) {
                        return false;
                    }
                    if ($canBet->count() === 0) {
                        return true;
                    }
                    if ((int) $p->street_contribution === (int) $hand->current_bet && $p->acted_this_street) {
                        continue;
                    }

                    return false;
                }
            }

            return true;
        }

        foreach ($canBet as $p) {
            if (! $p->acted_this_street) {
                return false;
            }
            if ((int) $p->street_contribution !== (int) $hand->current_bet) {
                return false;
            }
        }

        return true;
    }

    private function moveToAwaiting(PokerHand $hand): void
    {
        $hand->current_turn_user_id = null;

        $hand->status = match ($hand->street) {
            PokerHand::STREET_PREFLOP => PokerHand::STATUS_AWAITING_FLOP,
            PokerHand::STREET_FLOP => PokerHand::STATUS_AWAITING_TURN,
            PokerHand::STREET_TURN => PokerHand::STATUS_AWAITING_RIVER,
            PokerHand::STREET_RIVER => PokerHand::STATUS_AWAITING_SHOWDOWN,
            default => $hand->status,
        };

        if ($hand->status === PokerHand::STATUS_AWAITING_SHOWDOWN) {
            $hand->street = PokerHand::STREET_SHOWDOWN;
        }

        $hand->save();
    }

    private function nextToAct(PokerHand $hand): ?PokerHandPlayer
    {
        $currentSeat = PokerHandPlayer::where('hand_id', $hand->id)
            ->where('user_id', $hand->current_turn_user_id)
            ->value('seat');

        // Candidates: active players who still owe action: either they have
        // not acted, or they face an unmatched bet. All-in / folded never
        // receive a new betting turn.
        $ordered = PokerHandPlayer::where('hand_id', $hand->id)
            ->where('status', PokerHandPlayer::STATUS_ACTIVE)
            ->orderBy('seat')
            ->get();

        if ($ordered->isEmpty()) {
            return null;
        }

        $seats = $ordered->pluck('seat')->toArray();
        sort($seats);

        $startIdx = 0;
        if ($currentSeat !== null) {
            foreach ($seats as $i => $s) {
                if ($s > (int) $currentSeat) {
                    $startIdx = $i;
                    break;
                }
                $startIdx = 0;
            }
            // If current seat is beyond all seats, wrap to 0 (already set).
            $beyondAll = true;
            foreach ($seats as $i => $s) {
                if ($s > (int) $currentSeat) {
                    $startIdx = $i;
                    $beyondAll = false;
                    break;
                }
            }
            if ($beyondAll) {
                $startIdx = 0;
            }
        }

        $count = count($seats);
        for ($k = 0; $k < $count; $k++) {
            $seat = $seats[($startIdx + $k) % $count];
            $candidate = $ordered->firstWhere('seat', $seat);
            if ($candidate === null) {
                continue;
            }
            if (! $candidate->acted_this_street) {
                return $candidate;
            }
            if ((int) $candidate->street_contribution < (int) $hand->current_bet) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Last player standing wins immediately. Folded contributions stay in
     * the pot; uncalled excess returns to the winner through the ledger.
     */
    private function settleFoldWin(PokerHand $hand, PokerHandPlayer $winner, string $idempotencyKey): void
    {
        $all = PokerHandPlayer::where('hand_id', $hand->id)->get();

        $inputs = $all->map(fn (PokerHandPlayer $p): array => [
            'user_id' => $p->user_id,
            'seat' => $p->seat,
            'total' => (int) $p->total_contribution,
            'folded' => $p->status === PokerHandPlayer::STATUS_FOLDED,
        ])->toArray();

        $calc = PotCalculator::calculate($inputs, (int) $hand->dealer_seat);

        $winnerStack = TableStack::where('room_id', $hand->room_id)
            ->where('user_id', $winner->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        $payout = 0;
        foreach ($calc['pots'] as $i => $pot) {
            $payout += (int) $pot['amount'];

            ChipTransaction::create([
                'from_poker_hand_id' => $hand->id,
                'to_table_stack_id' => $winnerStack->id,
                'to_hand_player_id' => $winner->id,
                'type' => ChipTransaction::TYPE_POT_WIN,
                'amount' => (int) $pot['amount'],
                'idempotency_key' => $idempotencyKey.':foldwin:pot:'.$i,
                'metadata' => [
                    'hand_id' => $hand->id,
                    'player_id' => $winner->id,
                    'pot_layer' => $i,
                    'win_type' => 'fold_win',
                ],
            ]);
        }

        if ($calc['uncalled'] !== null) {
            $payout += (int) $calc['uncalled']['amount'];

            ChipTransaction::create([
                'from_poker_hand_id' => $hand->id,
                'to_table_stack_id' => $winnerStack->id,
                'to_hand_player_id' => $winner->id,
                'type' => ChipTransaction::TYPE_UNCALLED_RETURN,
                'amount' => (int) $calc['uncalled']['amount'],
                'idempotency_key' => $idempotencyKey.':foldwin:uncalled',
                'metadata' => [
                    'hand_id' => $hand->id,
                    'player_id' => $winner->id,
                    'win_type' => 'fold_win',
                ],
            ]);
        }

        $winnerStack->balance += $payout;
        $winnerStack->save();

        $hand->status = PokerHand::STATUS_SETTLED;
        $hand->street = PokerHand::STREET_SHOWDOWN;
        $hand->current_turn_user_id = null;
        $hand->completed_at = Carbon::now();
        $hand->pot_layers = $calc['pots'];
        $hand->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActionMetadata(string $action, int $contribution, ?int $raiseTo, bool $isTimeout): array
    {
        $meta = ['action' => $action, 'contribution' => $contribution];
        if ($raiseTo !== null) {
            $meta['raise_to'] = $raiseTo;
        }
        if ($isTimeout) {
            $meta['timeout'] = true;
        }

        return $meta;
    }

    private function mapActionToTransactionType(string $action): string
    {
        return match ($action) {
            'call' => ChipTransaction::TYPE_CALL,
            'bet' => ChipTransaction::TYPE_BET,
            'raise' => ChipTransaction::TYPE_RAISE,
            'all_in' => ChipTransaction::TYPE_BET,
            default => ChipTransaction::TYPE_BET,
        };
    }

    /**
     * @throws IdempotencyConflictException
     */
    private function assertReplayMatches(
        PokerAction $existing,
        User $player,
        PokerHand $hand,
        string $action,
        ?int $amount,
        ?int $raiseTo,
        bool $isTimeout = false,
    ): void {
        $same = $existing->user_id === $player->id
            && $existing->hand_id === $hand->id
            && $existing->action === $action
            && (bool) ($existing->metadata['timeout'] ?? false) === $isTimeout;

        if ($same && $action === 'bet' && $amount !== null) {
            $recorded = (int) ($existing->metadata['contribution'] ?? $existing->amount);
            $same = $recorded === $amount;
        }

        if ($same && $action === 'raise' && $raiseTo !== null) {
            $recordedTo = (int) ($existing->metadata['raise_to'] ?? $existing->raise_to ?? -1);
            $same = $recordedTo === $raiseTo;
        }

        if (! $same) {
            throw new IdempotencyConflictException('Operation ID was already used with different parameters.');
        }
    }

    /**
     * @return array{hand: PokerHand, player: ?PokerHandPlayer, action: PokerAction, replayed: bool, street_complete: bool}
     */
    private function replay(PokerAction $existing): array
    {
        $hand = PokerHand::with('players')->findOrFail($existing->hand_id);
        $player = $hand->players->firstWhere('user_id', $existing->user_id);

        return [
            'hand' => $hand,
            'player' => $player,
            'action' => $existing,
            'replayed' => true,
            'street_complete' => false,
        ];
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
