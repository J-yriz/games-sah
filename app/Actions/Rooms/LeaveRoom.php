<?php

namespace App\Actions\Rooms;

use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\StackNotEmptyException;
use App\Events\RoomStateUpdated;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Leave a room. Players must cash out their stack first; a host may
 * only leave an empty room, which closes it.
 */
class LeaveRoom
{
    /**
     * @throws NotMemberException
     * @throws StackNotEmptyException
     * @throws InvalidRoomStateException
     */
    public function handle(User $user, Room $room): void
    {
        $member = $room->members()->where('user_id', $user->id)->first();

        if ($member === null || ! $member->isActive()) {
            throw new NotMemberException('You are not an active member of this room.');
        }

        $stackBalance = (int) ($room->stacks()->where('user_id', $user->id)->value('balance') ?? 0);

        if ($stackBalance > 0) {
            throw new StackNotEmptyException('Cash out your table stack before leaving.');
        }

        if ($this->isSeatedInActiveHand($room->id, $user->id)) {
            throw new InvalidRoomStateException('You cannot leave in the middle of a hand.');
        }

        DB::transaction(function () use ($room, $member, $user): void {
            if ($member->isHost()) {
                $others = $room->members()
                    ->where('user_id', '!=', $user->id)
                    ->where('status', RoomMember::STATUS_ACTIVE)
                    ->count();

                if ($others > 0) {
                    throw new InvalidRoomStateException('The host cannot leave while other players remain.');
                }

                $room->status = Room::STATUS_CANCELLED;
                $room->save();
            }

            $member->status = RoomMember::STATUS_LEFT;
            $member->save();
        });

        RoomStateUpdated::dispatch($room->id);
    }

    private function isSeatedInActiveHand(int $roomId, int $userId): bool
    {
        $activeHandIds = PokerHand::where('room_id', $roomId)
            ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
            ->pluck('id');

        if ($activeHandIds->isEmpty()) {
            return false;
        }

        return PokerHandPlayer::whereIn('hand_id', $activeHandIds)
            ->where('user_id', $userId)
            ->exists();
    }
}
