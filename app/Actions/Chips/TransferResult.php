<?php

namespace App\Actions\Chips;

use App\Models\ChipTransaction;

/**
 * Outcome of a chip transfer. `replayed` is true when the request
 * arrived with an idempotency key that was already processed.
 */
readonly class TransferResult
{
    public function __construct(
        public ChipTransaction $transaction,
        public bool $replayed,
        public int $senderBalance,
        public int $recipientBalance,
    ) {}
}
