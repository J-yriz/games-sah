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
 * Move chips from a room table stack back to the owner's account
 * wallet. Only the stack owner may cash out, and only their own
 * stack. Same wallet-then-stack lock order as buy-in.
 */
class CashOutFromRoom
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
    ): StackMoveResult {
        $this->validateAmount($amount);
        $this->validateIdempotencyKey($idempotencyKey);

        $member = $this->activeMember($player, $room);

        if ($room->status === Room::STATUS_COMPLETED || $room->status === Room::STATUS_CANCELLED) {
            throw new InvalidRoomStateException('This room is closed.');
        }

        $existing = ChipTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $member, $amount);
        }

        $wallet = $this->ensureWallet($player);
        $stack = TableStack::where('room_id', $room->id)->where('user_id', $player->id)->first();

        if ($stack === null) {
            throw new InsufficientBalanceException('No table stack to cash out.');
        }

        try {
            $transaction = DB::transaction(function () use (
                $wallet, $stack, $player, $room, $amount, $idempotencyKey,
            ): ChipTransaction {
                // Global lock order: wallet first, then stack.
                $lockedWallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $lockedStack = TableStack::whereKey($stack->id)->lockForUpdate()->firstOrFail();

                if ($lockedStack->balance < $amount) {
                    throw new InsufficientBalanceException('Insufficient table stack balance.');
                }

                $lockedStack->balance -= $amount;
                $lockedStack->save();

                $lockedWallet->balance += $amount;
                $lockedWallet->save();

                return ChipTransaction::create([
                    'from_wallet_id' => null,
                    'to_wallet_id' => $lockedWallet->id,
                    'from_table_stack_id' => $lockedStack->id,
                    'to_table_stack_id' => null,
                    'type' => ChipTransaction::TYPE_CASH_OUT,
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
                    return $this->replay($existing, $member, $amount);
                }
            }

            throw $e;
        }

        return $this->freshResult($transaction, false);
    }

    /**
     * @throws InvalidTransferAmountException
     */
    private function validateAmount(int $amount): void
    {
        $min = (int) config('chips.min_transfer', 1);
        $max = (int) config('chips.max_transfer', 1_000_000_000);

        if ($amount < $min || $amount > $max) {
            throw new InvalidTransferAmountException("Amount must be between {$min} and {$max} chips.");
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
            throw new NotMemberException('Only active room members can cash out.');
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

    /**
     * @throws RoomIdempotencyConflictException
     */
    private function replay(
        ChipTransaction $existing,
        RoomMember $member,
        int $amount,
    ): StackMoveResult {
        $matches = $existing->type === ChipTransaction::TYPE_CASH_OUT
            && (int) $existing->amount === $amount
            && (int) ($existing->metadata['room_id'] ?? 0) === $member->room_id
            && $existing->from_table_stack_id !== null;

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
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
