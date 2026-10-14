<?php

namespace App\Actions\Rooms;

use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\StackNotEmptyException;
use App\Events\RoomStateUpdated;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Host-only room management: settings, lifecycle, player removal.
 * Every method authorizes through RoomPolicy; none touches balances.
 */
class ManageRoom
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidRoomStateException
     */
    public function updateSettings(User $host, Room $room, array $data): Room
    {
        Gate::forUser($host)->authorize('manage', $room);

        if (! in_array($room->status, [Room::STATUS_WAITING, Room::STATUS_ACTIVE], true)) {
            throw new InvalidRoomStateException('Closed rooms cannot be edited.');
        }

        $activeCount = $room->members()->where('status', RoomMember::STATUS_ACTIVE)->count();

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'max_players' => ['required', 'integer', 'min:'.$activeCount, 'max:20'],
            'min_buy_in' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'max_buy_in' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'gte:min_buy_in'],
            'action_timer' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'action_timer_extension' => ['sometimes', 'integer', 'min:0', 'max:3600'],
        ])->validate();

        $room->fill($validated);
        $room->save();

        RoomStateUpdated::dispatch($room->id);

        return $room->refresh();
    }

    /**
     * @throws InvalidRoomStateException
     */
    public function start(User $host, Room $room): Room
    {
        Gate::forUser($host)->authorize('manage', $room);

        if ($room->status !== Room::STATUS_WAITING) {
            throw new InvalidRoomStateException('Only waiting rooms can be started.');
        }

        $room->status = Room::STATUS_ACTIVE;
        $room->save();

        RoomStateUpdated::dispatch($room->id);

        return $room->refresh();
    }

    /**
     * Closing requires every table stack to be empty so no chips are
     * stranded. Players cash out first; the host cannot seize stacks.
     *
     * @throws InvalidRoomStateException
     * @throws StackNotEmptyException
     */
    public function close(User $host, Room $room, string $status = Room::STATUS_COMPLETED): Room
    {
        Gate::forUser($host)->authorize('manage', $room);

        if (! in_array($status, [Room::STATUS_COMPLETED, Room::STATUS_CANCELLED], true)) {
            throw new InvalidRoomStateException('Invalid closing status.');
        }

        if (! in_array($room->status, [Room::STATUS_WAITING, Room::STATUS_ACTIVE], true)) {
            throw new InvalidRoomStateException('This room is already closed.');
        }

        $stranded = $room->stacks()->where('balance', '>', 0)->count();

        $openHand = $room->pokerHands()
            ->whereNotIn('status', [
                PokerHand::STATUS_SETTLED,
                PokerHand::STATUS_CANCELLED,
            ])
            ->exists();

        if ($openHand) {
            throw new InvalidRoomStateException('Finish or cancel the active hand before closing.');
        }

        if ($stranded > 0) {
            throw new StackNotEmptyException('All table stacks must be cashed out before closing.');
        }

        DB::transaction(function () use ($room, $status): void {
            $room->status = $status;
            $room->save();

            $room->members()->where('status', RoomMember::STATUS_ACTIVE)->update(['status' => RoomMember::STATUS_LEFT]);
        });

        RoomStateUpdated::dispatch($room->id);

        return $room->refresh();
    }

    /**
     * @throws NotMemberException
     * @throws StackNotEmptyException
     * @throws InvalidRoomStateException
     */
    public function removePlayer(User $host, Room $room, User $player): void
    {
        Gate::forUser($host)->authorize('manage', $room);

        if ($player->id === $host->id) {
            throw new InvalidRoomStateException('The host cannot remove themselves; leave or close the room instead.');
        }

        $member = $room->members()->where('user_id', $player->id)->first();

        if ($member === null || ! $member->isActive()) {
            throw new NotMemberException('That user is not an active member.');
        }

        $stackBalance = (int) ($room->stacks()->where('user_id', $player->id)->value('balance') ?? 0);

        if ($stackBalance > 0) {
            throw new StackNotEmptyException('The player must cash out before being removed.');
        }

        $inHand = PokerHand::where('room_id', $room->id)
            ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
            ->whereHas('players', fn ($q) => $q->where('user_id', $player->id))
            ->exists();

        if ($inHand) {
            throw new InvalidRoomStateException('A player in an active hand cannot be removed.');
        }

        $member->status = RoomMember::STATUS_REMOVED;
        $member->save();

        RoomStateUpdated::dispatch($room->id);
    }
}
