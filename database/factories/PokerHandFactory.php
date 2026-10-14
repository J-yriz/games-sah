<?php

namespace Database\Factories;

use App\Models\PokerHand;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokerHand>
 */
class PokerHandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'hand_number' => 1,
            'dealer_user_id' => User::factory(),
            'dealer_seat' => 0,
            'small_blind_amount' => 1,
            'big_blind_amount' => 2,
            'street' => PokerHand::STREET_PREFLOP,
            'status' => PokerHand::STATUS_PREFLOP,
            'current_bet' => 0,
            'min_full_raise' => 0,
            'total_pot' => 0,
            'elapsed_seconds' => 0,
        ];
    }
}
