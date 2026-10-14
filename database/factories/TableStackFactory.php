<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableStack>
 */
class TableStackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'balance' => 0,
        ];
    }
}
