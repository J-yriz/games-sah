<?php

namespace App\Broadcasting;

use App\Models\RoomMember;
use App\Models\User;

/**
 * Authorize room channel subscriptions. One pattern serves both the
 * private state channel and the presence channel: members receive
 * their public identity payload, non-members receive an empty
 * (denied) response. Never includes email or secrets.
 */
class RoomChannel
{
    /**
     * @return array<string, mixed>
     */
    public function join(User $user, int $roomId): array
    {
        $member = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();

        if (! $member) {
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
