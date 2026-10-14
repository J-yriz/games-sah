<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorize subscriptions to a wallet's private balance channel.
 * Only the wallet owner may listen; the channel carries balances.
 */
class WalletChannel
{
    public function join(User $user, int $walletId): bool
    {
        return $user->wallet?->id === $walletId;
    }
}
