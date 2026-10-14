<?php

namespace App\Actions\Rooms;

use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Events\RoomStateUpdated;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stand up from the table: releases the seat (NULL) without leaving the
 * room. Refused while the player is seated in an active hand. Presence
 * (WebSocket online state) never triggers this implicitly.
 */
class StandUp
{
    /**
     * @throws NotMemberException
     * @throws InvalidRoomStateException
     */
    public function handle(User $user, Room $room): RoomMember
    {
        $member = DB::transaction(function () use ($user, $room): RoomMember {
            $lockedMember = RoomMember::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('status', RoomMember::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($lockedMember === null) {
                throw new NotMemberException('You are not an active member of this room.');
            }

            if ($lockedMember->seat === null) {
                return $lockedMember;
            }

            $activeHandIds = PokerHand::where('room_id', $room->id)
                ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
                ->pluck('id');

            $inHand = ! $activeHandIds->isEmpty() && PokerHandPlayer::whereIn('hand_id', $activeHandIds)
                ->where('user_id', $user->id)
                ->exists();

            if ($inHand) {
                throw new InvalidRoomStateException('You cannot stand up in the middle of a hand.');
            }

            $lockedMember->seat = null;
            $lockedMember->save();

            return $lockedMember;
        });

        RoomStateUpdated::dispatch($room->id);

        return $member;
    }
}
