<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'invite_code' => Str::upper(Str::random(8)),
            'game' => 'poker',
            'max_players' => 8,
            'min_buy_in' => 100,
            'max_buy_in' => 10_000,
            'settings' => null,
            'status' => Room::STATUS_WAITING,
        ];
    }
}
