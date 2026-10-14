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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Take a seat at the table: automatic (first free) or manual.
 *
 * Rules:
 * - Seat range is 0 .. max_players - 1.
 * - Serialized on the room row; unique(room_id, seat) is the last guard.
 * - Same seat may be reused across rooms.
 * - Changing seats while seated in an active hand is refused so turn order
 *   and snapshots stay intact. Re-taking the current seat is idempotent.
 */
class TakeSeat
{
    /**
     * @throws NotMemberException
     * @throws InvalidRoomStateException
     */
    public function handle(User $user, Room $room, ?int $seat = null): RoomMember
    {
        if ($seat !== null && ($seat < 0 || $seat > 1000)) {
            throw new InvalidRoomStateException('Invalid seat number.');
        }

        try {
            $member = DB::transaction(function () use ($user, $room, $seat): RoomMember {
                $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

                $lockedMember = RoomMember::where('room_id', $lockedRoom->id)
                    ->where('user_id', $user->id)
                    ->where('status', RoomMember::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if ($lockedMember === null) {
                    throw new NotMemberException('You are not an active member of this room.');
                }

                $maxSeats = (int) $lockedRoom->max_players;

                if ($seat !== null && $seat >= $maxSeats) {
                    throw new InvalidRoomStateException('That seat is outside the table capacity.');
                }

                if ($lockedMember->seat !== null && $seat !== null && (int) $lockedMember->seat === $seat) {
                    return $lockedMember;
                }

                if ($this->isSeatedInActiveHand($lockedRoom->id, $user->id)) {
                    throw new InvalidRoomStateException('You cannot change seats in the middle of a hand.');
                }

                $target = $seat ?? $this->firstFreeSeat($lockedRoom->id, $maxSeats);

                if ($target === null) {
                    throw new InvalidRoomStateException('No free seats at this table.');
                }

                $lockedMember->seat = $target;
                $lockedMember->save();

                return $lockedMember;
            });
        } catch (QueryException $e) {
            if ($this->isSeatConflict($e)) {
                throw new InvalidRoomStateException('That seat was just taken. Pick another seat.');
            }

            throw $e;
        }

        RoomStateUpdated::dispatch($room->id);

        return $member;
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

    private function firstFreeSeat(int $roomId, int $maxSeats): ?int
    {
        $taken = RoomMember::where('room_id', $roomId)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->whereNotNull('seat')
            ->pluck('seat')
            ->map(fn ($s): int => (int) $s)
            ->all();

        for ($seat = 0; $seat < $maxSeats; $seat++) {
            if (! in_array($seat, $taken, true)) {
                return $seat;
            }
        }

        return null;
    }

    private function isSeatConflict(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'room_members_room_seat_unique')
            || str_contains($e->getMessage(), 'room_members_seat_unique')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
