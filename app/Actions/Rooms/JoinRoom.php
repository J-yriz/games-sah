<?php

namespace App\Actions\Rooms;

use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\RoomFullException;
use App\Actions\Rooms\Exceptions\RoomNotFoundException;
use App\Events\RoomStateUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Join a room through its invite code. Idempotent: an active member
 * re-joining receives their existing membership.
 */
class JoinRoom
{
    /**
     * @throws RoomNotFoundException
     * @throws InvalidRoomStateException
     * @throws RoomFullException
     */
    public function handle(User $user, string $inviteCode): RoomMember
    {
        $code = Str::upper(trim($inviteCode));

        $room = Room::where('invite_code', $code)->first();

        if ($room === null) {
            throw new RoomNotFoundException('Invite code not found.');
        }

        $existing = $room->members()
            ->where('user_id', $user->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $room->isOpenForJoin()) {
            throw new InvalidRoomStateException('This room is no longer open for new players.');
        }

        try {
            $member = DB::transaction(function () use ($room, $user): RoomMember {
                // Serialize joins on the room row so concurrent requests
                // cannot overshoot max_players.
                $locked = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

                $count = $locked->members()
                    ->where('status', RoomMember::STATUS_ACTIVE)
                    ->count();

                if ($count >= $locked->max_players) {
                    throw new RoomFullException('This room is full.');
                }

                return $locked->members()->create([
                    'user_id' => $user->id,
                    'role' => Room::ROLE_PLAYER,
                    'status' => RoomMember::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Lost race with an identical join: unique(room,user) fired.
            if ($this->isUniqueViolation($e)) {
                $member = $room->members()
                    ->where('user_id', $user->id)
                    ->where('status', RoomMember::STATUS_ACTIVE)
                    ->first();

                if ($member !== null) {
                    return $member;
                }
            }

            throw $e;
        }

        RoomStateUpdated::dispatch($room->id);

        return $member;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'room_members_room_id_user_id_unique')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
