<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\HandNotFoundException;
use App\Actions\Poker\Exceptions\IdempotencyConflictException;
use App\Actions\Poker\Exceptions\InsufficientStackException;
use App\Actions\Poker\Exceptions\InvalidActionException;
use App\Actions\Poker\Exceptions\InvalidBetAmountException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\Exceptions\PlayerNotInHandException;
use App\Events\PokerHandStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Handle player betting actions: check, call, bet, raise, fold, all_in.
 */
class PlayerAction
{
    /**
     * @throws HandNotFoundException
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
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);

        // Fast-path replay
        $existing = PokerAction::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing);
        }

        // Validate hand state
        if (! $hand->isBettingStreet()) {
            throw new InvalidHandStateException('Betting is not allowed on the current street.');
        }

        // Get player in this hand
        $handPlayer = $hand->players()->where('user_id', $player->id)->first();

        if ($handPlayer === null) {
            throw new PlayerNotInHandException('You are not a participant in this hand.');
        }

        if (! $handPlayer->canAct()) {
            if ($handPlayer->status === PokerHandPlayer::STATUS_FOLDED) {
                throw new InvalidActionException('You have already folded.');
            }
            if ($handPlayer->status === PokerHandPlayer::STATUS_ALL_IN) {
                throw new InvalidActionException('You are all-in and cannot act further.');
            }
            throw new InvalidActionException('You cannot act right now.');
        }

        // Check turn
        if ($hand->current_turn_user_id !== $player->id) {
            throw new NotYourTurnException('It is not your turn to act.');
        }

        $stack = $handPlayer->stack;
        if ($stack === null) {
            throw new InsufficientStackException('No table stack found for this hand.');
        }

        $currentBet = $hand->current_bet;
        $streetContribution = $handPlayer->street_contribution;
        $totalContribution = $handPlayer->total_contribution;
        $stackBalance = $stack->balance;

        try {
            $result = DB::transaction(function () use (
                $hand,
                $handPlayer,
                $player,
                $action,
                $idempotencyKey,
                $amount,
                $raiseTo,
                $currentBet,
                $streetContribution,
                $stackBalance,
                $stack,
            ): array {
                // Lock hand and stack
                $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();
                $lockedPlayer = PokerHandPlayer::whereKey($handPlayer->id)->lockForUpdate()->firstOrFail();
                $lockedStack = TableStack::whereKey($stack->id)->lockForUpdate()->firstOrFail();

                // Re-validate state inside transaction
                if ($lockedHand->current_turn_user_id !== $player->id) {
                    throw new NotYourTurnException('It is not your turn to act.');
                }
                if (! $lockedPlayer->canAct()) {
                    throw new InvalidActionException('You can no longer act.');
                }

                $contribution = 0;
                $newStatus = $lockedPlayer->status;
                $acted = true;

                switch ($action) {
                    case 'check':
                        $this->validateCheck($lockedHand, $lockedPlayer, $currentBet, $streetContribution);
                        break;

                    case 'call':
                        $contribution = $this->validateCall($lockedHand, $lockedPlayer, $currentBet, $streetContribution, $stackBalance);
                        break;

                    case 'bet':
                        $contribution = $this->validateBet($lockedHand, $lockedPlayer, $amount, $stackBalance, $currentBet);
                        break;

                    case 'raise':
                        $contribution = $this->validateRaise($lockedHand, $lockedPlayer, $raiseTo, $currentBet, $stackBalance, $streetContribution);
                        break;

                    case 'fold':
                        $newStatus = PokerHandPlayer::STATUS_FOLDED;
                        $acted = true;
                        break;

                    case 'all_in':
                        $contribution = $this->validateAllIn($lockedHand, $lockedPlayer, $stackBalance, $streetContribution);
                        if ($contribution === 0) {
                            // Already all-in (shouldn't happen due to earlier check)
                        }
                        break;

                    default:
                        throw new InvalidActionException('Unknown action: '.$action);
                }

                // Apply contribution
                if ($contribution > 0) {
                    $this->applyContribution($lockedHand, $lockedPlayer, $lockedStack, $contribution, $action, $idempotencyKey);
                }

                // Update player state
                $lockedPlayer->acted_this_street = $acted;
                if ($newStatus !== $lockedPlayer->status) {
                    $lockedPlayer->status = $newStatus;
                }
                $lockedPlayer->save();

                // Advance turn or check if street is complete
                $this->advanceTurnOrCompleteStreet($lockedHand);

                // Create action record
                $pokerAction = PokerAction::create([
                    'hand_id' => $lockedHand->id,
                    'user_id' => $player->id,
                    'street' => $lockedHand->street,
                    'action' => $action,
                    'amount' => $contribution,
                    'raise_to' => $raiseTo ?? ($action === 'raise' ? $currentBet + $contribution : null),
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $this->buildActionMetadata($action, $contribution, $raiseTo),
                ]);

                // Create ledger entry
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
                            'username' => $player->username,
                        ],
                    ]);
                }

                return [
                    'hand' => $lockedHand->refresh(),
                    'player' => $lockedPlayer->refresh(),
                    'action' => $pokerAction,
                    'street_complete' => $this->isStreetComplete($lockedHand),
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = PokerAction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $this->replay($existing);
                }
            }
            throw $e;
        }

        PokerHandStateUpdated::dispatch($hand->id);

        return $result;
    }

    /**
     * @throws InvalidBetAmountException
     */
    private function validateCheck(PokerHand $hand, PokerHandPlayer $player, int $currentBet, int $streetContribution): void
    {
        if ($streetContribution < $currentBet) {
            throw new InvalidBetAmountException('You must call or raise. Checking is not allowed when there is a bet to match.');
        }
    }

    /**
     * @return int contribution amount
     *
     * @throws InvalidBetAmountException
     * @throws InsufficientStackException
     */
    private function validateCall(PokerHand $hand, PokerHandPlayer $player, int $currentBet, int $streetContribution, int $stackBalance): int
    {
        $needed = $currentBet - $streetContribution;

        if ($needed <= 0) {
            throw new InvalidBetAmountException('No bet to call. You may check instead.');
        }

        if ($stackBalance < $needed) {
            // Short call (all-in for remaining chips)
            return $stackBalance;
        }

        return $needed;
    }

    /**
     * @return int contribution amount
     *
     * @throws InvalidBetAmountException
     * @throws InsufficientStackException
     */
    private function validateBet(PokerHand $hand, PokerHandPlayer $player, ?int $amount, int $stackBalance, int $currentBet): int
    {
        if ($currentBet > 0) {
            throw new InvalidBetAmountException('Cannot bet when there is already a bet. Use raise instead.');
        }

        if ($amount === null || $amount <= 0) {
            throw new InvalidBetAmountException('Bet amount must be a positive integer.');
        }

        $minBet = $hand->big_blind_amount;

        if ($amount < $minBet) {
            throw new InvalidBetAmountException("Minimum bet is {$minBet} chips.");
        }

        if ($stackBalance < $amount) {
            throw new InsufficientStackException('Insufficient stack for this bet.');
        }

        return $amount;
    }

    /**
     * @return int contribution amount
     *
     * @throws InvalidBetAmountException
     * @throws InsufficientStackException
     */
    private function validateRaise(PokerHand $hand, PokerHandPlayer $player, ?int $raiseTo, int $currentBet, int $stackBalance, int $streetContribution): int
    {
        if ($currentBet === 0) {
            throw new InvalidBetAmountException('Cannot raise when there is no bet. Use bet instead.');
        }

        if ($raiseTo === null || $raiseTo <= 0) {
            throw new InvalidBetAmountException('Raise target (raise_to) must be specified and positive.');
        }

        if ($raiseTo <= $currentBet) {
            throw new InvalidBetAmountException('Raise target must be greater than current bet.');
        }

        $minRaiseTo = $currentBet + $hand->min_full_raise;
        if ($raiseTo < $minRaiseTo && $streetContribution + ($raiseTo - $streetContribution) < $stackBalance) {
            throw new InvalidBetAmountException("Minimum raise to is {$minRaiseTo} chips (current bet: {$currentBet}, min full raise: {$hand->min_full_raise}).");
        }

        $additional = $raiseTo - $streetContribution;

        if ($stackBalance < $additional) {
            throw new InsufficientStackException('Insufficient stack for this raise.');
        }

        return $additional;
    }

    /**
     * @return int contribution amount (remaining stack)
     */
    private function validateAllIn(PokerHand $hand, PokerHandPlayer $player, int $stackBalance, int $streetContribution): int
    {
        if ($stackBalance <= 0) {
            return 0;
        }

        return $stackBalance;
    }

    private function applyContribution(
        PokerHand $hand,
        PokerHandPlayer $player,
        TableStack $stack,
        int $contribution,
        string $action,
        string $idempotencyKey,
    ): void {
        // Deduct from stack
        $stack->balance -= $contribution;
        $stack->save();

        // Update player contributions
        $player->street_contribution += $contribution;
        $player->total_contribution += $contribution;

        // Update hand
        $hand->current_bet = max($hand->current_bet, $player->street_contribution);
        $hand->total_pot += $contribution;

        // Update min_full_raise if this is a raise
        if (in_array($action, ['bet', 'raise', 'all_in'], true) && $player->street_contribution > $hand->current_bet) {
            $lastRaise = $player->street_contribution - $hand->current_bet;
            $hand->min_full_raise = max($hand->min_full_raise, $lastRaise);
            $hand->last_aggressor_user_id = $player->user_id;
        }

        $hand->save();
    }

    private function advanceTurnOrCompleteStreet(PokerHand $hand): void
    {
        // Check if street is complete
        if ($this->isStreetComplete($hand)) {
            $this->completeStreet($hand);

            return;
        }

        // Find next player to act
        $nextPlayer = $this->getNextActivePlayer($hand);

        if ($nextPlayer !== null) {
            $hand->current_turn_user_id = $nextPlayer->user_id;
            $hand->save();
        }
    }

    /**
     * Check if betting street is complete.
     * Complete when all active players have acted and contributions are equal,
     * or only one player remains who can bet.
     */
    private function isStreetComplete(PokerHand $hand): bool
    {
        $activePlayers = $hand->players()
            ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->get();

        if ($activePlayers->count() <= 1) {
            return true;
        }

        $canBet = $activePlayers->where('status', PokerHandPlayer::STATUS_ACTIVE);

        // If everyone who can bet has acted and contributions match current_bet
        $allActed = $canBet->every(fn ($p) => $p->acted_this_street);
        $allMatched = $canBet->every(fn ($p) => $p->street_contribution === $hand->current_bet);

        return $allActed && $allMatched;
    }

    private function completeStreet(PokerHand $hand): void
    {
        // Reset acted flags for next street
        $hand->players()->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->update(['acted_this_street' => false, 'street_contribution' => 0]);

        // Advance street
        $nextStreet = $this->getNextStreet($hand->street);

        if ($nextStreet === null) {
            $hand->status = PokerHand::STATUS_AWAITING_SHOWDOWN;
            $hand->street = PokerHand::STREET_SHOWDOWN;
            $hand->current_turn_user_id = null;
        } else {
            $hand->street = $nextStreet;
            $hand->status = $nextStreet;
            $hand->current_bet = 0;
            $hand->min_full_raise = $hand->big_blind_amount; // Reset min raise for new street
            $hand->last_aggressor_user_id = null;

            // First to act: first active player left of dealer
            $firstToAct = $this->getFirstToAct($hand);
            if ($firstToAct !== null) {
                $hand->current_turn_user_id = $firstToAct->user_id;
            }
        }

        $hand->save();
    }

    private function getNextStreet(string $street): ?string
    {
        return match ($street) {
            PokerHand::STREET_PREFLOP => PokerHand::STREET_FLOP,
            PokerHand::STREET_FLOP => PokerHand::STREET_TURN,
            PokerHand::STREET_TURN => PokerHand::STREET_RIVER,
            PokerHand::STREET_RIVER => null,
            default => null,
        };
    }

    private function getFirstToAct(PokerHand $hand): ?PokerHandPlayer
    {
        $dealerSeat = $hand->dealer_seat;
        $seatedPlayers = $hand->players()
            ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->orderBy('seat')
            ->get();

        if ($seatedPlayers->isEmpty()) {
            return null;
        }

        // First active player left of dealer
        foreach ($seatedPlayers as $player) {
            if ($player->seat > $dealerSeat) {
                return $player;
            }
        }

        // Wrap around
        return $seatedPlayers->first();
    }

    private function getNextActivePlayer(PokerHand $hand): ?PokerHandPlayer
    {
        $currentSeat = $hand->players()->where('user_id', $hand->current_turn_user_id)->value('seat');

        if ($currentSeat === null) {
            return null;
        }

        $activePlayers = $hand->players()
            ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->where('seat', '>', $currentSeat)
            ->orderBy('seat')
            ->get();

        if ($activePlayers->isNotEmpty()) {
            return $activePlayers->first();
        }

        // Wrap around
        return $hand->players()
            ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->orderBy('seat')
            ->first();
    }

    private function buildActionMetadata(string $action, int $contribution, ?int $raiseTo): array
    {
        $meta = ['action' => $action, 'contribution' => $contribution];
        if ($raiseTo !== null) {
            $meta['raise_to'] = $raiseTo;
        }

        return $meta;
    }

    private function mapActionToTransactionType(string $action): string
    {
        return match ($action) {
            'check' => ChipTransaction::TYPE_BET, // No chips moved
            'call' => ChipTransaction::TYPE_CALL,
            'bet' => ChipTransaction::TYPE_BET,
            'raise' => ChipTransaction::TYPE_RAISE,
            'fold' => ChipTransaction::TYPE_BET, // No chips moved
            'all_in' => ChipTransaction::TYPE_BET,
            default => ChipTransaction::TYPE_BET,
        };
    }

    /**
     * @throws IdempotencyConflictException
     */
    private function replay(PokerAction $existing): array
    {
        // Check if the replay request matches the original
        // For now, just return the hand state
        $hand = $existing->hand()->with(['players', 'players.stack'])->firstOrFail();
        $player = $hand->players()->where('user_id', $existing->user_id)->first();

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
