<?php

namespace App\Events;

use App\Models\ChipTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies one wallet owner that their balance changed.
 *
 * Dispatched only after the transfer transaction commits. Carries the
 * minimum the UI needs; the browser must still reload authoritative
 * state from the database (event is a notification, not truth).
 */
class ChipBalanceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $walletId,
        public int $balance,
        public ChipTransaction $transaction,
        public string $direction,
        public string $counterpartUsername,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('wallets.'.$this->walletId)];
    }

    public function broadcastAs(): string
    {
        return 'balance.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'balance' => $this->balance,
            'transaction_id' => $this->transaction->id,
            'type' => $this->transaction->type,
            'amount' => (int) $this->transaction->amount,
            'direction' => $this->direction,
            'counterpart_username' => $this->counterpartUsername,
            'occurred_at' => $this->transaction->created_at?->toIso8601String(),
        ];
    }
}
