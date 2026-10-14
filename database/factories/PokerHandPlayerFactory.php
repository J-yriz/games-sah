<?php

namespace Database\Factories;

use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokerHandPlayer>
 */
class PokerHandPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hand_id' => PokerHand::factory(),
            'user_id' => User::factory(),
            'room_id' => Room::factory(),
            'seat' => 0,
            'starting_stack' => 1000,
            'total_contribution' => 0,
            'street_contribution' => 0,
            'status' => PokerHandPlayer::STATUS_ACTIVE,
            'acted_this_street' => false,
            'is_dealer' => false,
            'is_small_blind' => false,
            'is_big_blind' => false,
        ];
    }
}
