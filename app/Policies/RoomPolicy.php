<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;

class RoomPolicy
{
    /**
     * Room state is private: only active members may view it.
     * Knowing the room ID alone grants nothing.
     */
    public function view(User $user, Room $room): bool
    {
        return $room->members()
            ->where('user_id', $user->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * Hand history stays readable after a room closes: active members, plus
     * anyone who was ever a member of a closed room. Live table state still
     * requires view().
     */
    public function viewHistory(User $user, Room $room): bool
    {
        if ($this->view($user, $room)) {
            return true;
        }

        return in_array($room->status, [Room::STATUS_COMPLETED, Room::STATUS_CANCELLED], true)
            && $room->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Only an active host member may change settings or lifecycle.
     */
    public function manage(User $user, Room $room): bool
    {
        return $room->members()
            ->where('user_id', $user->id)
            ->where('role', Room::ROLE_HOST)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->exists();
    }
}
