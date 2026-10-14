<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\HandNotFoundException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\PlayerNotInHandException;
use App\Events\PokerHandStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Start a new poker hand in a room.
 *
 * Requirements:
 * - Room must be active
 * - Host must authorize
 * - At least 2 active players with positive stacks
 * - No existing active hand in the room
 */
class StartHand
{
    /**
     * @throws HandNotFoundException
     * @throws InvalidHandStateException
     * @throws PlayerNotInHandException
     */
    public function handle(User $host, Room $room, string $idempotencyKey): PokerHand
    {
        $this->validateIdempotencyKey($idempotencyKey);

        $existing = PokerHand::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing);
        }

        return DB::transaction(function () use ($room, $idempotencyKey): PokerHand {
            // Lock room to prevent concurrent hand starts
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($lockedRoom->status !== Room::STATUS_ACTIVE) {
                throw new InvalidHandStateException('Room is not active.');
            }

            // Check for existing active hand
            $activeHand = $lockedRoom->pokerHands()
                ->whereIn('status', [
                    PokerHand::STATUS_PREFLOP,
                    PokerHand::STATUS_AWAITING_FLOP,
                    PokerHand::STATUS_FLOP,
                    PokerHand::STATUS_AWAITING_TURN,
                    PokerHand::STATUS_TURN,
                    PokerHand::STATUS_AWAITING_RIVER,
                    PokerHand::STATUS_RIVER,
                    PokerHand::STATUS_AWAITING_SHOWDOWN,
                ])
                ->first();

            if ($activeHand !== null) {
                throw new InvalidHandStateException('A hand is already in progress.');
            }

            // Get eligible seated players with positive stacks
            $seatedMembers = $this->getSeatedPlayersWithStacks($lockedRoom);

            if (count($seatedMembers) < 2) {
                throw new InvalidHandStateException('At least two seated players with chips are required.');
            }

            $handNumber = $this->getNextHandNumber($lockedRoom);
            $dealerSeat = $this->determineDealerSeat($lockedRoom, $seatedMembers);
            $blinds = $this->resolveBlinds($lockedRoom);

            $hand = PokerHand::create([
                'room_id' => $lockedRoom->id,
                'hand_number' => $handNumber,
                'dealer_user_id' => $seatedMembers[$dealerSeat]['user_id'],
                'dealer_seat' => $dealerSeat,
                'small_blind_amount' => $blinds['small'],
                'big_blind_amount' => $blinds['big'],
                'street' => PokerHand::STREET_PREFLOP,
                'status' => PokerHand::STATUS_PREFLOP,
                'current_bet' => $blinds['big'],
                'min_full_raise' => $blinds['big'],
                'total_pot' => 0,
                'idempotency_key' => $idempotencyKey,
                'started_at' => now(),
            ]);

            // Create hand players and post blinds
            $this->initializeHandPlayers($hand, $seatedMembers, $dealerSeat, $blinds);

            PokerHandStateUpdated::dispatch($hand->id);

            return $hand;
        });
    }

    /**
     * @throws InvalidHandStateException
     */
    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    /**
     * Get seated active members with positive stacks, ordered by seat.
     *
     * @return array<int, array{user_id: int, seat: int, stack: int}>
     */
    private function getSeatedPlayersWithStacks(Room $room): array
    {
        return RoomMember::where('room_members.room_id', $room->id)
            ->where('room_members.status', RoomMember::STATUS_ACTIVE)
            ->whereNotNull('room_members.seat')
            ->join('table_stacks', function ($join) use ($room): void {
                $join->on('room_members.user_id', '=', 'table_stacks.user_id')
                    ->where('table_stacks.room_id', '=', $room->id);
            })
            ->where('table_stacks.balance', '>', 0)
            ->orderBy('room_members.seat')
            ->get(['room_members.user_id', 'room_members.seat', 'table_stacks.balance as stack'])
            ->map(fn ($m) => ['user_id' => $m->user_id, 'seat' => (int) $m->seat, 'stack' => (int) $m->stack])
            ->values()
            ->toArray();
    }

    /**
     * Determine dealer seat for new hand.
     * Rotates to next seated player clockwise from previous dealer.
     */
    private function determineDealerSeat(Room $room, array $seatedMembers): int
    {
        $lastHand = $room->pokerHands()
            ->whereNotNull('dealer_seat')
            ->latest('hand_number')
            ->first();

        if ($lastHand === null) {
            return 0; // First hand, seat 0 is dealer
        }

        $lastDealerSeat = $lastHand->dealer_seat;
        $seats = array_column($seatedMembers, 'seat');

        // Find next seated seat after last dealer
        foreach ($seats as $seat) {
            if ($seat > $lastDealerSeat) {
                return $seat;
            }
        }

        // Wrap around to first seat
        return $seats[0];
    }

    /**
     * Resolve blind amounts from room config.
     */
    private function resolveBlinds(Room $room): array
    {
        return [
            'small' => (int) $room->small_blind,
            'big' => (int) $room->big_blind,
        ];
    }

    /**
     * Get next hand number for room.
     */
    private function getNextHandNumber(Room $room): int
    {
        return (int) ($room->pokerHands()->max('hand_number') ?? 0) + 1;
    }

    /**
     * Initialize hand players: create PokerHandPlayer records and post blinds.
     *
     * @param  array<int, array{user_id: int, seat: int, stack: int}>  $seatedMembers
     */
    private function initializeHandPlayers(
        PokerHand $hand,
        array $seatedMembers,
        int $dealerSeat,
        array $blinds
    ): void {
        $count = count($seatedMembers);
        $smallBlindSeat = ($dealerSeat + 1) % $count;
        $bigBlindSeat = ($dealerSeat + 2) % $count;

        foreach ($seatedMembers as $index => $member) {
            $seat = $member['seat'];
            $isDealer = $seat === $dealerSeat;
            $isSmallBlind = $seat === $smallBlindSeat;
            $isBigBlind = $seat === $bigBlindSeat;

            $player = PokerHandPlayer::create([
                'hand_id' => $hand->id,
                'user_id' => $member['user_id'],
                'room_id' => $hand->room_id,
                'seat' => $seat,
                'starting_stack' => $member['stack'],
                'total_contribution' => 0,
                'street_contribution' => 0,
                'status' => PokerHandPlayer::STATUS_ACTIVE,
                'is_dealer' => $isDealer,
                'is_small_blind' => $isSmallBlind,
                'is_big_blind' => $isBigBlind,
            ]);

            // Post blinds
            if ($isSmallBlind) {
                $this->postBlind($hand, $player, $blinds['small'], 'small');
            } elseif ($isBigBlind) {
                $this->postBlind($hand, $player, $blinds['big'], 'big');
            }
        }

        // Set first to act (after big blind)
        $firstToActSeat = ($bigBlindSeat + 1) % $count;
        $firstToAct = $hand->players()->where('seat', $firstToActSeat)->first();

        if ($firstToAct !== null) {
            $hand->current_turn_user_id = $firstToAct->user_id;
            $hand->save();
        }
    }

    /**
     * Post blind for a player.
     */
    private function postBlind(PokerHand $hand, PokerHandPlayer $player, int $amount, string $blindType): void
    {
        $actualAmount = min($amount, $player->starting_stack);

        // Update player contributions
        $player->total_contribution += $actualAmount;
        $player->street_contribution += $actualAmount;
        $player->save();

        // Update hand
        $hand->total_pot += $actualAmount;
        $hand->save();

        // Create action record
        PokerAction::create([
            'hand_id' => $hand->id,
            'user_id' => $player->user_id,
            'street' => PokerHand::STREET_PREFLOP,
            'action' => PokerAction::ACTION_POST_BLIND,
            'amount' => $actualAmount,
            'idempotency_key' => 'blind:'.$hand->id.':'.$player->user_id.':'.$blindType,
            'metadata' => ['blind_type' => $blindType],
        ]);

        // Create ledger entry: stack -> hand (escrow)
        $stack = $player->stack;
        if ($stack !== null && $actualAmount > 0) {
            ChipTransaction::create([
                'from_table_stack_id' => $stack->id,
                'to_poker_hand_id' => $hand->id,
                'from_hand_player_id' => $player->id,
                'type' => ChipTransaction::TYPE_BLIND_POST,
                'amount' => $actualAmount,
                'idempotency_key' => 'blind:'.$hand->id.':'.$player->user_id.':'.$blindType,
                'metadata' => [
                    'hand_id' => $hand->id,
                    'player_id' => $player->id,
                    'blind_type' => $blindType,
                    'username' => $player->user->username,
                ],
            ]);
        }
    }

    /**
     * @throws HandNotFoundException
     */
    private function replay(PokerHand $existing): PokerHand
    {
        return $existing;
    }
}
