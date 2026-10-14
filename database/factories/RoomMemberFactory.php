<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomMember>
 */
class RoomMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'role' => Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ];
    }
}
