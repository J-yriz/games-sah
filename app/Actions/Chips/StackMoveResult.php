<?php

namespace App\Actions\Chips;

use App\Models\ChipTransaction;

/**
 * Outcome of a buy-in / rebuy / cash-out. `replayed` is true when the
 * request arrived with an idempotency key that was already processed.
 */
readonly class StackMoveResult
{
    public function __construct(
        public ChipTransaction $transaction,
        public bool $replayed,
        public int $walletBalance,
        public int $stackBalance,
    ) {}
}
