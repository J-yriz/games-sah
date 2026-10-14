<?php

namespace App\Actions\Chips;

use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\RoomIdempotencyConflictException;
use App\Events\ChipBalanceUpdated;
use App\Events\RoomStateUpdated;
use App\Models\ChipTransaction;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Move chips from an account wallet onto a room table stack.
 *
 * Covers both buy-in (first chips onto the table) and rebuy (top-up);
 * the caller states which explicitly so the ledger stays truthful.
 * Lock order is always wallet-then-stack to serialize against cash-out.
 */
class BuyInToRoom
{
    /**
     * @throws NotMemberException
     * @throws InvalidRoomStateException
     * @throws InvalidTransferAmountException
     * @throws InsufficientBalanceException
     * @throws RoomIdempotencyConflictException
     */
    public function handle(
        User $player,
        Room $room,
        int $amount,
        string $idempotencyKey,
        string $type = ChipTransaction::TYPE_BUY_IN,
    ): StackMoveResult {
        if (! in_array($type, [ChipTransaction::TYPE_BUY_IN, ChipTransaction::TYPE_REBUY], true)) {
            throw new InvalidTransferAmountException('Invalid stack operation type.');
        }

        $this->validateAmount($room, $amount);
        $this->validateIdempotencyKey($idempotencyKey);

        $member = $this->activeMember($player, $room);

        if (! in_array($room->status, [Room::STATUS_WAITING, Room::STATUS_ACTIVE], true)) {
            throw new InvalidRoomStateException('This room is not accepting buy-ins.');
        }

        $existing = ChipTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $member, $amount, $type);
        }

        $wallet = $this->ensureWallet($player);
        $stack = $this->ensureStack($room, $player);

        try {
            $transaction = DB::transaction(function () use (
                $wallet, $stack, $player, $room, $amount, $idempotencyKey, $type,
            ): ChipTransaction {
                // Global lock order: wallet first, then stack.
                $lockedWallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $lockedStack = TableStack::whereKey($stack->id)->lockForUpdate()->firstOrFail();

                if ($lockedWallet->balance < $amount) {
                    throw new InsufficientBalanceException('Insufficient chip balance.');
                }

                $lockedWallet->balance -= $amount;
                $lockedWallet->save();

                $lockedStack->balance += $amount;
                $lockedStack->save();

                return ChipTransaction::create([
                    'from_wallet_id' => $lockedWallet->id,
                    'to_wallet_id' => null,
                    'from_table_stack_id' => null,
                    'to_table_stack_id' => $lockedStack->id,
                    'type' => $type,
                    'amount' => $amount,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => [
                        'room_id' => $room->id,
                        'username' => $player->username,
                    ],
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = ChipTransaction::where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $member, $amount, $type);
                }
            }

            throw $e;
        }

        return $this->freshResult($transaction, false);
    }

    /**
     * @throws InvalidTransferAmountException
     */
    private function validateAmount(Room $room, int $amount): void
    {
        $min = max(1, $room->min_buy_in);
        $max = $room->max_buy_in ?? 1_000_000_000;

        if ($amount < $min || $amount > $max) {
            throw new InvalidTransferAmountException("Buy-in must be between {$min} and {$max} chips for this room.");
        }
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    /**
     * @throws NotMemberException
     */
    private function activeMember(User $player, Room $room): RoomMember
    {
        $member = $room->members()->where('user_id', $player->id)->first();

        if ($member === null || ! $member->isActive()) {
            throw new NotMemberException('Only active room members can buy in.');
        }

        return $member;
    }

    private function ensureWallet(User $player): Wallet
    {
        $wallet = $player->wallet;

        if ($wallet !== null) {
            return $wallet;
        }

        $grant = (int) config('chips.initial_grant');

        return DB::transaction(function () use ($player, $grant): Wallet {
            $wallet = Wallet::create(['user_id' => $player->id, 'balance' => $grant]);

            ChipTransaction::firstOrCreate(
                ['idempotency_key' => 'initial-grant:'.$player->id],
                [
                    'from_wallet_id' => null,
                    'to_wallet_id' => $wallet->id,
                    'type' => ChipTransaction::TYPE_INITIAL_GRANT,
                    'amount' => $grant,
                    'metadata' => ['user_id' => $player->id, 'grant' => 'initial-lazy'],
                ],
            );

            return $wallet;
        });
    }

    private function ensureStack(Room $room, User $player): TableStack
    {
        $stack = TableStack::where('room_id', $room->id)->where('user_id', $player->id)->first();

        if ($stack !== null) {
            return $stack;
        }

        try {
            return TableStack::create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 0]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return TableStack::where('room_id', $room->id)->where('user_id', $player->id)->firstOrFail();
        }
    }

    /**
     * @throws RoomIdempotencyConflictException
     */
    private function replay(
        ChipTransaction $existing,
        RoomMember $member,
        int $amount,
        string $type,
    ): StackMoveResult {
        $matches = $existing->type === $type
            && (int) $existing->amount === $amount
            && (int) ($existing->metadata['room_id'] ?? 0) === $member->room_id
            && $existing->to_table_stack_id !== null;

        if (! $matches) {
            throw new RoomIdempotencyConflictException('Operation ID was already used with different parameters.');
        }

        return $this->freshResult($existing, true);
    }

    private function freshResult(ChipTransaction $transaction, bool $replayed): StackMoveResult
    {
        $wallet = $transaction->from_wallet_id !== null
            ? Wallet::find($transaction->from_wallet_id)
            : Wallet::find($transaction->to_wallet_id);
        $stack = $transaction->to_table_stack_id !== null
            ? TableStack::find($transaction->to_table_stack_id)
            : TableStack::find($transaction->from_table_stack_id);

        $result = new StackMoveResult(
            $transaction,
            $replayed,
            $wallet === null ? 0 : $wallet->balance,
            $stack === null ? 0 : $stack->balance,
        );

        if (! $replayed) {
            $this->dispatchEvents($transaction, $result);
        }

        return $result;
    }

    private function dispatchEvents(ChipTransaction $transaction, StackMoveResult $result): void
    {
        $username = (string) ($transaction->metadata['username'] ?? '');
        $roomId = (int) ($transaction->metadata['room_id'] ?? 0);

        if ($transaction->from_wallet_id !== null) {
            ChipBalanceUpdated::dispatch(
                $transaction->from_wallet_id, $result->walletBalance, $transaction, 'out', 'table'
            );
        } else {
            ChipBalanceUpdated::dispatch(
                $transaction->to_wallet_id, $result->walletBalance, $transaction, 'in', 'table'
            );
        }

        if ($roomId > 0) {
            RoomStateUpdated::dispatch($roomId);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key')
            || str_contains($e->getMessage(), 'table_stacks_room_id_user_id_unique')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
