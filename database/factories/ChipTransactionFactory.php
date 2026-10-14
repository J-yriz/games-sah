<?php

namespace Database\Factories;

use App\Models\ChipTransaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChipTransaction>
 */
class ChipTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_wallet_id' => Wallet::factory(),
            'to_wallet_id' => Wallet::factory(),
            'type' => ChipTransaction::TYPE_TRANSFER,
            'amount' => fake()->numberBetween(1, 1000),
            'idempotency_key' => fake()->unique()->uuid(),
            'metadata' => null,
        ];
    }
}
