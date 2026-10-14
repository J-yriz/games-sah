<?php

namespace App\Broadcasting;

use App\Models\PokerHand;
use App\Models\RoomMember;
use App\Models\User;

/**
 * Authorize poker hand channel subscriptions.
 * Any active room member may watch the table (no hole cards are dealt
 * digitally); hand participants are a subset of room members.
 */
class PokerHandChannel
{
    public function join(User $user, int $handId): bool
    {
        $hand = PokerHand::find($handId);

        if ($hand === null) {
            return false;
        }

        return RoomMember::where('room_id', $hand->room_id)
            ->where('user_id', $user->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * Presence payload for online players in the hand.
     *
     * @return array<string, mixed>
     */
    public function joinPresence(User $user, int $handId): array
    {
        if (! $this->join($user, $handId)) {
            return [];
        }

        return [
            'id' => $user->id,
            'info' => [
                'username' => $user->username,
                'name' => $user->name,
            ],
        ];
    }
}
