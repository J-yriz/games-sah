<?php

namespace App\Broadcasting;

use App\Models\PokerHandPlayer;
use App\Models\User;

/**
 * Authorize poker hand channel subscriptions.
 * Only active participants in the hand may listen.
 */
class PokerHandChannel
{
    public function join(User $user, int $handId): bool
    {
        return PokerHandPlayer::where('hand_id', $handId)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                PokerHandPlayer::STATUS_ACTIVE,
                PokerHandPlayer::STATUS_ALL_IN,
                PokerHandPlayer::STATUS_FOLDED, // Folded players can still watch
            ])
            ->exists();
    }

    /**
     * Presence payload for online players in the hand.
     *
     * @return array<string, mixed>
     */
    public function joinPresence(User $user, int $handId): array
    {
        if (! $this->join($user, $handId)) {
            return [];
        }

        return [
            'id' => $user->id,
            'info' => [
                'username' => $user->username,
                'name' => $user->name,
            ],
        ];
    }
}
