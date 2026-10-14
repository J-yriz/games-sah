<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\InvalidSettlementException;
use App\Events\PokerHandStateUpdated;
use App\Events\RoomStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use App\Support\PotCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settle a hand awaiting showdown: validate host-picked winners per pot,
 * split pots with deterministic odd chips, return uncalled excess, and
 * credit winners through the auditable ledger.
 *
 * Physical-cards assumption: the server never sees hole/community cards.
 * The host reviews the physical table and submits winners; the backend
 * only verifies eligibility and performs integer-exact accounting.
 */
class SettleHand
{
    /**
     * @param  array<int, array<int, int>>  $winners  pot_index => [user_id, ...]
     * @return array<string, mixed>
     *
     * @throws InvalidHandStateException
     * @throws InvalidSettlementException
     */
    public function handle(
        User $host,
        PokerHand $hand,
        array $winners,
        string $idempotencyKey,
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);

        // Idempotent replay on the settlement key (never touches the
        // hand-start idempotency key).
        $replayHand = PokerHand::where('settlement_key', $idempotencyKey)->first();
        if ($replayHand !== null) {
            return $this->replay($replayHand);
        }

        if ($hand->status === PokerHand::STATUS_SETTLED) {
            throw new InvalidHandStateException('Hand is already settled.');
        }

        if ($hand->status !== PokerHand::STATUS_AWAITING_SHOWDOWN) {
            throw new InvalidHandStateException('Hand is not ready for settlement.');
        }

        $isHost = RoomMember::where('room_id', $hand->room_id)
            ->where('user_id', $host->id)
            ->where('role', Room::ROLE_HOST)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();

        if (! $isHost) {
            throw new InvalidSettlementException('Only the room host can settle the hand.');
        }

        try {
            $result = DB::transaction(function () use ($hand, $winners, $idempotencyKey): array {
                $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();

                if ($lockedHand->status === PokerHand::STATUS_SETTLED) {
                    // A concurrent request with the same key may have settled
                    // while this request waited on the hand lock.
                    if ($lockedHand->settlement_key === $idempotencyKey) {
                        return $this->replay($lockedHand);
                    }

                    throw new InvalidHandStateException('Hand is already settled.');
                }

                if ($lockedHand->status !== PokerHand::STATUS_AWAITING_SHOWDOWN) {
                    throw new InvalidHandStateException('Hand is not ready for settlement.');
                }

                $all = PokerHandPlayer::where('hand_id', $lockedHand->id)
                    ->lockForUpdate()
                    ->get();

                if ($all->isEmpty()) {
                    throw new InvalidSettlementException('No players in this hand.');
                }

                $inputs = $all->map(fn (PokerHandPlayer $p): array => [
                    'user_id' => $p->user_id,
                    'seat' => $p->seat,
                    'total' => (int) $p->total_contribution,
                    'folded' => $p->status === PokerHandPlayer::STATUS_FOLDED,
                ])->toArray();

                $calc = PotCalculator::calculate($inputs, (int) $lockedHand->dealer_seat);
                $pots = $calc['pots'];

                if ($pots === []) {
                    throw new InvalidSettlementException('No pots to distribute.');
                }

                $this->validateWinners($pots, $winners, $all);

                // Lock winner stacks in id order (deadlock-safe).
                $winnerIds = collect($winners)->flatten()->unique()->sort()->values()->toArray();
                $stacks = TableStack::where('room_id', $lockedHand->room_id)
                    ->whereIn('user_id', $winnerIds)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get()
                    ->keyBy('user_id');

                $distributions = [];
                foreach ($pots as $index => $pot) {
                    $layerWinners = array_values(array_unique($winners[$index] ?? []));
                    $shares = PotCalculator::splitPot(
                        (int) $pot['amount'],
                        $layerWinners,
                        $inputs,
                        (int) $lockedHand->dealer_seat
                    );

                    $layerDist = [];
                    foreach ($shares as $winnerId => $share) {
                        if ($share <= 0) {
                            continue;
                        }
                        $stack = $stacks->get($winnerId);
                        $handPlayer = $all->firstWhere('user_id', $winnerId);
                        if ($stack === null || $handPlayer === null) {
                            throw new InvalidSettlementException("Winner {$winnerId} has no table stack.");
                        }

                        $stack->balance += $share;
                        $stack->save();

                        ChipTransaction::create([
                            'from_poker_hand_id' => $lockedHand->id,
                            'to_table_stack_id' => $stack->id,
                            'to_hand_player_id' => $handPlayer->id,
                            'type' => ChipTransaction::TYPE_POT_WIN,
                            'amount' => $share,
                            'idempotency_key' => $idempotencyKey.':pot:'.$index.':winner:'.$winnerId,
                            'metadata' => [
                                'hand_id' => $lockedHand->id,
                                'player_id' => $handPlayer->id,
                                'pot_layer' => $index,
                            ],
                        ]);

                        $layerDist[] = [
                            'user_id' => $winnerId,
                            'amount' => $share,
                            'stack_after' => (int) $stack->balance,
                        ];
                    }

                    // Integrity: layer payout must equal layer amount.
                    $paid = array_sum(array_column($layerDist, 'amount'));
                    if ($paid !== (int) $pot['amount']) {
                        throw new InvalidSettlementException("Pot layer {$index} payout mismatch.");
                    }

                    $distributions[] = [
                        'pot_amount' => (int) $pot['amount'],
                        'eligible' => $pot['eligible'],
                        'winners' => $layerDist,
                    ];
                }

                $uncalledReturns = [];
                if ($calc['uncalled'] !== null) {
                    $uncalled = $calc['uncalled'];
                    $handPlayer = $all->firstWhere('user_id', $uncalled['user_id']);
                    $stack = $stacks->get($uncalled['user_id']);

                    // Uncalled excess may belong to a non-winner (e.g. lone
                    // aggressor who then lost the lower pots). Lock separately.
                    if ($stack === null) {
                        $stack = TableStack::where('room_id', $lockedHand->room_id)
                            ->where('user_id', $uncalled['user_id'])
                            ->lockForUpdate()
                            ->first();
                    }

                    if ($stack !== null && $handPlayer !== null && (int) $uncalled['amount'] > 0) {
                        $stack->balance += (int) $uncalled['amount'];
                        $stack->save();

                        ChipTransaction::create([
                            'from_poker_hand_id' => $lockedHand->id,
                            'to_table_stack_id' => $stack->id,
                            'to_hand_player_id' => $handPlayer->id,
                            'type' => ChipTransaction::TYPE_UNCALLED_RETURN,
                            'amount' => (int) $uncalled['amount'],
                            'idempotency_key' => $idempotencyKey.':uncalled:'.$uncalled['user_id'],
                            'metadata' => [
                                'hand_id' => $lockedHand->id,
                                'player_id' => $handPlayer->id,
                            ],
                        ]);

                        $uncalledReturns[] = [
                            'user_id' => $uncalled['user_id'],
                            'amount' => (int) $uncalled['amount'],
                            'stack_after' => (int) $stack->balance,
                        ];
                    }
                }

                $lockedHand->status = PokerHand::STATUS_SETTLED;
                $lockedHand->completed_at = Carbon::now();
                $lockedHand->pot_layers = $pots;
                $lockedHand->settlement_key = $idempotencyKey;
                $lockedHand->save();

                return [
                    'hand' => $lockedHand->refresh(),
                    'pot_layers' => $pots,
                    'distributions' => $distributions,
                    'uncalled_returns' => $uncalledReturns,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $replayHand = PokerHand::where('settlement_key', $idempotencyKey)->first();
                if ($replayHand !== null) {
                    return $this->replay($replayHand);
                }
            }

            throw $e;
        }

        PokerHandStateUpdated::dispatch($hand->id, 'settled');
        RoomStateUpdated::dispatch($hand->room_id);

        return $result;
    }

    /**
     * @param  array<int, array{amount: int, eligible: array<int, int>}>  $pots
     * @param  array<int, array<int, int>>  $winners
     * @param  Collection<int, PokerHandPlayer>  $all
     *
     * @throws InvalidSettlementException
     */
    private function validateWinners(array $pots, array $winners, $all): void
    {
        $byUser = $all->keyBy('user_id');

        foreach ($pots as $index => $pot) {
            $layerWinners = $winners[$index] ?? [];

            if ($layerWinners === []) {
                throw new InvalidSettlementException("No winners specified for pot layer {$index}.");
            }

            foreach (array_unique($layerWinners) as $winnerId) {
                if (! in_array($winnerId, $pot['eligible'], true)) {
                    throw new InvalidSettlementException("Winner {$winnerId} is not eligible for pot layer {$index}.");
                }

                $player = $byUser->get($winnerId);
                if ($player === null) {
                    throw new InvalidSettlementException("Winner {$winnerId} is not in this hand.");
                }

                if ($player->status === PokerHandPlayer::STATUS_FOLDED) {
                    throw new InvalidSettlementException("Winner {$winnerId} has folded.");
                }
            }
        }
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    /**
     * @return array{hand: PokerHand, pot_layers: mixed, distributions: array<int, mixed>, uncalled_returns: array<int, mixed>, replayed: bool}
     */
    private function replay(PokerHand $existing): array
    {
        return [
            'hand' => $existing,
            'pot_layers' => $existing->pot_layers ?? [],
            'distributions' => [],
            'uncalled_returns' => [],
            'replayed' => true,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key')
            || str_contains($e->getMessage(), 'settlement_key')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
