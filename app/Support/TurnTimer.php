<?php

namespace App\Support;

use App\Models\PokerHand;
use Illuminate\Support\Carbon;

/**
 * Server-authoritative action timer.
 *
 * Deadlines are persisted on the hand; browsers only count down. Arming is
 * idempotent per turn: every turn change re-arms (or clears when the timer
 * is off, nobody is to act, or betting is closed).
 */
class TurnTimer
{
    public static function arm(PokerHand $hand, ?int $turnUserId): void
    {
        $seconds = (int) ($hand->room->action_timer ?? 0);

        if ($seconds <= 0 || $turnUserId === null || ! $hand->isBettingStreet()
            || in_array($hand->status, [
                PokerHand::STATUS_AWAITING_FLOP,
                PokerHand::STATUS_AWAITING_TURN,
                PokerHand::STATUS_AWAITING_RIVER,
                PokerHand::STATUS_AWAITING_SHOWDOWN,
                PokerHand::STATUS_SETTLED,
                PokerHand::STATUS_CANCELLED,
            ], true)) {
            self::clear($hand);

            return;
        }

        $now = Carbon::now();
        $hand->turn_started_at = $now;
        $hand->turn_deadline_at = $now->copy()->addSeconds($seconds);
        $hand->turn_extended = false;
        $hand->save();
    }

    public static function clear(PokerHand $hand): void
    {
        $hand->turn_started_at = null;
        $hand->turn_deadline_at = null;
        $hand->turn_extended = false;
        $hand->save();
    }
}
