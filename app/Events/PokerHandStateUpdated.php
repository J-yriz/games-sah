<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies room members that poker hand state changed.
 */
class PokerHandStateUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $handId,
        public ?string $trigger = null, // 'action', 'street_complete', 'settled', etc.
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('poker-hands.'.$this->handId)];
    }

    public function broadcastAs(): string
    {
        return 'poker.hand.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'hand_id' => $this->handId,
            'trigger' => $this->trigger,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
