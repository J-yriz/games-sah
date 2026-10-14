<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\IdempotencyConflictException;
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
use App\Support\TurnTimer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a hand as a misdeal (physical-cards error confirmed by the host).
 *
 * Policy:
 * - Host-only, reason required.
 * - Allowed on any non-terminal hand (betting or awaiting-host states),
 *   whether or not betting has started.
 * - Settled hands can never be cancelled; cancelled hands can never
 *   resume or settle.
 * - Every chip a player contributed is refunded in full to their own stack
 *   through auditable `refund` ledger rows. Blind/betting history is kept;
 *   nothing is deleted.
 * - Same cancel key replays; same key with a different reason conflicts.
 *
 * Lock discipline: hand, then players, then stacks in id order — the same
 * order as SettleHand, so cancel races safely with actions and settlement.
 */
class CancelHand
{
    /**
     * @return array{hand: PokerHand, refunds: array<int, array{user_id: int, amount: int}>, replayed?: bool}
     *
     * @throws InvalidHandStateException
     * @throws InvalidSettlementException
     * @throws IdempotencyConflictException
     */
    public function handle(User $host, PokerHand $hand, string $reason, string $idempotencyKey): array
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new InvalidHandStateException('A cancellation reason is required (max 500 characters).');
        }
        $this->validateIdempotencyKey($idempotencyKey);

        $byKey = PokerHand::where('cancel_key', $idempotencyKey)->first();
        if ($byKey !== null) {
            $this->assertReplayMatches($byKey, $reason);

            return $this->replay($byKey);
        }

        if ($hand->status === PokerHand::STATUS_SETTLED) {
            throw new InvalidHandStateException('A settled hand cannot be cancelled.');
        }

        if ($hand->status === PokerHand::STATUS_CANCELLED) {
            throw new InvalidHandStateException('This hand is already cancelled.');
        }

        $isHost = RoomMember::where('room_id', $hand->room_id)
            ->where('user_id', $host->id)
            ->where('role', Room::ROLE_HOST)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();

        if (! $isHost) {
            throw new InvalidSettlementException('Only the room host can cancel a hand.');
        }

        try {
            $result = DB::transaction(function () use ($hand, $host, $reason, $idempotencyKey): array {
                $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();

                if ($lockedHand->status === PokerHand::STATUS_CANCELLED) {
                    if ($lockedHand->cancel_key === $idempotencyKey) {
                        $this->assertReplayMatches($lockedHand, $reason);

                        return $this->replay($lockedHand);
                    }

                    throw new InvalidHandStateException('This hand is already cancelled.');
                }

                if ($lockedHand->status === PokerHand::STATUS_SETTLED) {
                    throw new InvalidHandStateException('A settled hand cannot be cancelled.');
                }

                $players = PokerHandPlayer::where('hand_id', $lockedHand->id)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                $stacks = TableStack::where('room_id', $lockedHand->room_id)
                    ->whereIn('user_id', $players->pluck('user_id'))
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get()
                    ->keyBy('user_id');

                $refunds = [];
                foreach ($players as $player) {
                    $total = (int) $player->total_contribution;
                    if ($total <= 0) {
                        continue;
                    }

                    $stack = $stacks->get($player->user_id);
                    if ($stack === null) {
                        throw new InvalidHandStateException('A player stack is missing; cannot refund safely.');
                    }

                    $stack->balance += $total;
                    $stack->save();

                    ChipTransaction::create([
                        'from_poker_hand_id' => $lockedHand->id,
                        'to_table_stack_id' => $stack->id,
                        'to_hand_player_id' => $player->id,
                        'type' => ChipTransaction::TYPE_REFUND,
                        'amount' => $total,
                        'idempotency_key' => $idempotencyKey.':refund:'.$player->user_id,
                        'metadata' => [
                            'hand_id' => $lockedHand->id,
                            'player_id' => $player->id,
                            'reason' => $reason,
                        ],
                    ]);

                    $refunds[] = ['user_id' => $player->user_id, 'amount' => $total];
                }

                $lockedHand->status = PokerHand::STATUS_CANCELLED;
                $lockedHand->current_turn_user_id = null;
                $lockedHand->completed_at = Carbon::now();
                $lockedHand->cancel_reason = $reason;
                $lockedHand->cancelled_by_user_id = $host->id;
                $lockedHand->cancel_key = $idempotencyKey;
                $lockedHand->save();

                TurnTimer::clear($lockedHand);

                return [
                    'hand' => $lockedHand->refresh(),
                    'refunds' => $refunds,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $byKey = PokerHand::where('cancel_key', $idempotencyKey)->first();
                if ($byKey !== null) {
                    $this->assertReplayMatches($byKey, $reason);

                    return $this->replay($byKey);
                }
            }

            throw $e;
        }

        PokerHandStateUpdated::dispatch($hand->id, 'cancelled');
        RoomStateUpdated::dispatch($hand->room_id);

        return $result;
    }

    /**
     * @throws IdempotencyConflictException
     */
    private function assertReplayMatches(PokerHand $hand, string $reason): void
    {
        if (($hand->cancel_reason ?? '') !== $reason) {
            throw new IdempotencyConflictException('Operation ID was already used with a different reason.');
        }
    }

    /**
     * @return array{hand: PokerHand, refunds: array<int, array{user_id: int, amount: int}>, replayed: bool}
     */
    private function replay(PokerHand $hand): array
    {
        $byHandPlayerId = $hand->players()->pluck('user_id', 'id');

        $refunds = ChipTransaction::where('from_poker_hand_id', $hand->id)
            ->where('type', ChipTransaction::TYPE_REFUND)
            ->get()
            ->map(fn (ChipTransaction $tx): array => [
                // Stored player_id is the hand-player row; resolve to user.
                'user_id' => (int) ($byHandPlayerId->get($tx->metadata['player_id'] ?? $tx->to_hand_player_id) ?? 0),
                'amount' => (int) $tx->amount,
            ])
            ->all();

        return ['hand' => $hand, 'refunds' => $refunds, 'replayed' => true];
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
            || str_contains($e->getMessage(), 'cancel_key')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
