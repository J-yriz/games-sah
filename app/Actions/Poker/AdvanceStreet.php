<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Events\PokerHandStateUpdated;
use App\Events\RoomStateUpdated;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Support\TurnTimer;
use Illuminate\Support\Facades\DB;

/**
 * Host confirms the physical cards for the next street were dealt.
 *
 * Transitions (only from awaiting states):
 * - awaiting_flop -> flop, awaiting_turn -> turn,
 *   awaiting_river -> river, awaiting_showdown is terminal for this action
 *   (settled via SettleHand or fold-win).
 *
 * Resets the betting round: current_bet = 0, street contributions = 0,
 * acted flags cleared, first-to-act set to first active player left of
 * the dealer. Rejected while any betting action is still pending because
 * this action only runs from awaiting states, which PlayerAction enters
 * exactly when betting is complete.
 */
class AdvanceStreet
{
    /**
     * @throws InvalidHandStateException
     */
    public function handle(User $host, PokerHand $hand): PokerHand
    {
        $result = DB::transaction(function () use ($host, $hand): PokerHand {
            $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();

            $next = match ($lockedHand->status) {
                PokerHand::STATUS_AWAITING_FLOP => PokerHand::STREET_FLOP,
                PokerHand::STATUS_AWAITING_TURN => PokerHand::STREET_TURN,
                PokerHand::STATUS_AWAITING_RIVER => PokerHand::STREET_RIVER,
                default => null,
            };

            if ($next === null) {
                throw new InvalidHandStateException('Hand is not waiting for the host to open the next street.');
            }

            $isHost = RoomMember::where('room_id', $lockedHand->room_id)
                ->where('user_id', $host->id)
                ->where('role', Room::ROLE_HOST)
                ->where('status', RoomMember::STATUS_ACTIVE)
                ->exists();

            if (! $isHost) {
                throw new InvalidHandStateException('Only the room host can open the next street.');
            }

            $lockedHand->street = $next;
            $lockedHand->status = $next;
            $lockedHand->current_bet = 0;
            $lockedHand->min_full_raise = (int) $lockedHand->big_blind_amount;
            $lockedHand->last_aggressor_user_id = null;

            $lockedHand->save();

            PokerHandPlayer::where('hand_id', $lockedHand->id)
                ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
                ->update(['acted_this_street' => false, 'street_contribution' => 0]);

            $first = $this->firstToAct($lockedHand);
            $lockedHand->current_turn_user_id = $first?->user_id;
            $lockedHand->save();

            TurnTimer::arm($lockedHand, $lockedHand->current_turn_user_id);

            // All remaining players all-in: no one can bet, but the host
            // still steps through the physical deal street by street.
            // current_turn stays null in that case; AdvanceStreet remains
            // available until showdown.

            return $lockedHand->refresh();
        });

        PokerHandStateUpdated::dispatch($hand->id, 'street');
        RoomStateUpdated::dispatch($hand->room_id);

        return $result;
    }

    private function firstToAct(PokerHand $hand): ?PokerHandPlayer
    {
        $players = PokerHandPlayer::where('hand_id', $hand->id)
            ->where('status', PokerHandPlayer::STATUS_ACTIVE)
            ->orderBy('seat')
            ->get();

        if ($players->isEmpty()) {
            return null;
        }

        foreach ($players as $player) {
            if ((int) $player->seat > (int) $hand->dealer_seat) {
                return $player;
            }
        }

        return $players->first();
    }
}
