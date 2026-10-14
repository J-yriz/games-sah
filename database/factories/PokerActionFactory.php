<?php

namespace Database\Factories;

use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokerAction>
 */
class PokerActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hand_id' => PokerHand::factory(),
            'user_id' => User::factory(),
            'street' => 'preflop',
            'action' => PokerAction::ACTION_CHECK,
            'amount' => 0,
            'idempotency_key' => fake()->unique()->uuid(),
            'metadata' => null,
        ];
    }
}
