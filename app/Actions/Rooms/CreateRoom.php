<?php

namespace App\Actions\Rooms;

use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Create a room with the host as its first active member.
 */
class CreateRoom
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidRoomStateException
     */
    public function handle(User $host, array $data): Room
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'max_players' => ['required', 'integer', 'min:2', 'max:20'],
            'min_buy_in' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'max_buy_in' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'gte:min_buy_in'],
            'game' => ['sometimes', 'string', 'in:poker'],
        ])->validate();

        if (! $host->hasVerifiedEmail()) {
            throw new InvalidRoomStateException('Only verified accounts can host rooms.');
        }

        return DB::transaction(function () use ($host, $validated): Room {
            $room = Room::create([
                'host_user_id' => $host->id,
                'name' => $validated['name'],
                'invite_code' => $this->uniqueInviteCode(),
                'game' => $validated['game'] ?? 'poker',
                'max_players' => $validated['max_players'],
                'min_buy_in' => $validated['min_buy_in'],
                'max_buy_in' => $validated['max_buy_in'] ?? null,
                'status' => Room::STATUS_WAITING,
            ]);

            $room->members()->create([
                'user_id' => $host->id,
                'role' => Room::ROLE_HOST,
                'status' => RoomMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);

            return $room;
        });
    }

    /**
     * Short, unambiguous, unguessable invite codes with collision retry.
     */
    private function uniqueInviteCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = Str::upper(Str::random(8));

            // Ambiguous characters are kept out of invites.
            $code = strtr($code, ['0' => '2', 'O' => 'P', '1' => '3', 'I' => 'J']);

            if (! Room::where('invite_code', $code)->exists()) {
                return $code;
            }
        }

        throw new InvalidRoomStateException('Could not generate a unique invite code.');
    }
}
