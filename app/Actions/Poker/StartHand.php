<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Events\PokerHandStateUpdated;
use App\Events\RoomStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use App\Support\TurnTimer;
use Illuminate\Support\Facades\DB;

/**
 * Start a new poker hand in a room.
 *
 * Requirements (PRD 4.1):
 * - Room must be active.
 * - Caller must be an active host member (host may also play).
 * - At least 2 seated active members with positive table stacks.
 * - No other non-terminal hand in the room.
 * - Room small_blind / big_blind config valid.
 */
class StartHand
{
    /**
     * Lock discipline: room row first, then hand rows, then stacks in id
     * order. No other poker operation locks the room after taking a hand
     * lock, so concurrent starts serialize on the room without deadlocks.
     *
     * @throws InvalidHandStateException
     */
    public function handle(User $host, Room $room, string $idempotencyKey): PokerHand
    {
        $this->validateIdempotencyKey($idempotencyKey);

        $existing = PokerHand::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $hand = DB::transaction(function () use ($host, $room, $idempotencyKey): PokerHand {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            // Re-check inside the lock: a concurrent request with the same key
            // may have created the hand while this request waited on the lock.
            $replay = PokerHand::where('idempotency_key', $idempotencyKey)->first();
            if ($replay !== null) {
                return $replay;
            }

            if ($lockedRoom->status !== Room::STATUS_ACTIVE) {
                throw new InvalidHandStateException('Room is not active.');
            }

            // Host authorization: active host member. Host plays as a normal
            // player; this check grants no balance privileges.
            $isHost = RoomMember::where('room_id', $lockedRoom->id)
                ->where('user_id', $host->id)
                ->where('role', Room::ROLE_HOST)
                ->where('status', RoomMember::STATUS_ACTIVE)
                ->exists();

            if (! $isHost) {
                throw new InvalidHandStateException('Only the room host can start a hand.');
            }

            $this->validateBlinds($lockedRoom);

            $activeHand = $lockedRoom->pokerHands()
                ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
                ->lockForUpdate()
                ->first();

            if ($activeHand !== null) {
                throw new InvalidHandStateException('A hand is already in progress.');
            }

            $seated = $this->getSeatedPlayersWithStacks($lockedRoom);

            if (count($seated) < 2) {
                throw new InvalidHandStateException('At least two seated players with chips are required.');
            }

            $handNumber = (int) ($lockedRoom->pokerHands()->max('hand_number') ?? 0) + 1;
            $dealerSeat = $this->determineDealerSeat($lockedRoom, $seated);
            $dealerUserId = $this->userIdForSeat($seated, $dealerSeat);

            $small = (int) $lockedRoom->small_blind;
            $big = (int) $lockedRoom->big_blind;

            $hand = PokerHand::create([
                'room_id' => $lockedRoom->id,
                'hand_number' => $handNumber,
                'dealer_user_id' => $dealerUserId,
                'dealer_seat' => $dealerSeat,
                'small_blind_amount' => $small,
                'big_blind_amount' => $big,
                'street' => PokerHand::STREET_PREFLOP,
                'status' => PokerHand::STATUS_PREFLOP,
                'current_bet' => $big,
                'min_full_raise' => $big,
                'total_pot' => 0,
                'idempotency_key' => $idempotencyKey,
                'started_at' => now(),
            ]);

            $this->createPlayersAndPostBlinds($hand, $seated, $dealerSeat, $small, $big);

            return $hand;
        });

        PokerHandStateUpdated::dispatch($hand->id, 'started');
        RoomStateUpdated::dispatch($hand->room_id);

        return $hand;
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    /**
     * @throws InvalidHandStateException
     */
    private function validateBlinds(Room $room): void
    {
        $small = (int) $room->small_blind;
        $big = (int) $room->big_blind;

        if ($small < 1 || $big < 1) {
            throw new InvalidHandStateException('Blind configuration is invalid.');
        }

        if ($big < $small) {
            throw new InvalidHandStateException('Big blind must be at least the small blind.');
        }
    }

    /**
     * Seated active members with positive stacks, ordered by seat ascending.
     *
     * @return array<int, array{user_id: int, seat: int, stack_id: int, stack: int}>
     */
    private function getSeatedPlayersWithStacks(Room $room): array
    {
        $members = RoomMember::where('room_id', $room->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->whereNotNull('seat')
            ->orderBy('seat')
            ->get(['user_id', 'seat']);

        $stacks = TableStack::where('room_id', $room->id)
            ->whereIn('user_id', $members->pluck('user_id'))
            ->get(['id', 'user_id', 'balance'])
            ->keyBy('user_id');

        $seated = [];
        foreach ($members as $member) {
            $stack = $stacks->get($member->user_id);
            if ($stack === null || (int) $stack->balance <= 0) {
                continue;
            }
            $seated[] = [
                'user_id' => (int) $member->user_id,
                'seat' => (int) $member->seat,
                'stack_id' => (int) $stack->id,
                'stack' => (int) $stack->balance,
            ];
        }

        return $seated;
    }

    /**
     * @param  array<int, array{user_id: int, seat: int, stack_id: int, stack: int}>  $seated
     */
    private function determineDealerSeat(Room $room, array $seated): int
    {
        $seats = array_column($seated, 'seat');
        sort($seats);

        $lastHand = $room->pokerHands()
            ->whereNotNull('dealer_seat')
            ->orderByDesc('hand_number')
            ->first();

        if ($lastHand === null) {
            return $seats[0];
        }

        $lastDealerSeat = (int) $lastHand->dealer_seat;

        foreach ($seats as $seat) {
            if ($seat > $lastDealerSeat) {
                return $seat;
            }
        }

        return $seats[0];
    }

    /**
     * @param  array<int, array{user_id: int, seat: int, stack_id: int, stack: int}>  $seated
     */
    private function userIdForSeat(array $seated, int $seat): int
    {
        foreach ($seated as $s) {
            if ($s['seat'] === $seat) {
                return $s['user_id'];
            }
        }

        return $seated[0]['user_id'];
    }

    /**
     * @param  array<int, array{user_id: int, seat: int, stack_id: int, stack: int}>  $seated
     */
    private function createPlayersAndPostBlinds(
        PokerHand $hand,
        array $seated,
        int $dealerSeat,
        int $small,
        int $big
    ): void {
        $orderedSeats = array_column($seated, 'seat');
        sort($orderedSeats);
        $count = count($orderedSeats);

        // Heads-up: dealer posts small blind, other posts big blind.
        if ($count === 2) {
            $smallSeat = $dealerSeat;
            $bigSeat = $orderedSeats[0] === $dealerSeat ? $orderedSeats[1] : $orderedSeats[0];
        } else {
            $dealerIdx = array_search($dealerSeat, $orderedSeats, true);
            $smallSeat = $orderedSeats[($dealerIdx + 1) % $count];
            $bigSeat = $orderedSeats[($dealerIdx + 2) % $count];
        }

        // Lock all involved stacks in a consistent order to avoid deadlocks.
        $stackIds = array_column($seated, 'stack_id');
        sort($stackIds);
        $lockedStacks = TableStack::whereIn('id', $stackIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $stackByUser = [];
        foreach ($seated as $s) {
            $stackByUser[$s['user_id']] = $lockedStacks->get($s['stack_id']);
        }

        foreach ($seated as $member) {
            $isDealer = $member['seat'] === $dealerSeat;

            PokerHandPlayer::create([
                'hand_id' => $hand->id,
                'user_id' => $member['user_id'],
                'room_id' => $hand->room_id,
                'seat' => $member['seat'],
                'starting_stack' => $member['stack'],
                'total_contribution' => 0,
                'street_contribution' => 0,
                'status' => PokerHandPlayer::STATUS_ACTIVE,
                'acted_this_street' => false,
                'is_dealer' => $isDealer,
                'is_small_blind' => $member['seat'] === $smallSeat,
                'is_big_blind' => $member['seat'] === $bigSeat,
            ]);
        }

        // Post blinds in seat order (SB first, then BB) for determinism.
        $players = $hand->players()->get()->keyBy('seat');
        $totalPot = 0;

        foreach ([$smallSeat => $small, $bigSeat => $big] as $seat => $amount) {
            $player = $players->get($seat);
            if ($player === null) {
                continue;
            }

            $stack = $stackByUser[$player->user_id] ?? null;
            if ($stack === null) {
                continue;
            }

            $blindType = $seat === $smallSeat ? 'small' : 'big';
            $actual = min($amount, (int) $stack->balance);

            if ($actual > 0) {
                $stack->balance -= $actual;
                $stack->save();

                $player->total_contribution += $actual;
                $player->street_contribution += $actual;
                if ((int) $stack->balance === 0) {
                    $player->status = PokerHandPlayer::STATUS_ALL_IN;
                }
                $player->save();

                $totalPot += $actual;

                PokerAction::create([
                    'hand_id' => $hand->id,
                    'user_id' => $player->user_id,
                    'street' => PokerHand::STREET_PREFLOP,
                    'action' => PokerAction::ACTION_POST_BLIND,
                    'amount' => $actual,
                    'idempotency_key' => 'blind:'.$hand->id.':'.$player->user_id.':'.$blindType,
                    'metadata' => ['blind_type' => $blindType],
                ]);

                ChipTransaction::create([
                    'from_table_stack_id' => $stack->id,
                    'to_poker_hand_id' => $hand->id,
                    'from_hand_player_id' => $player->id,
                    'type' => ChipTransaction::TYPE_BLIND_POST,
                    'amount' => $actual,
                    'idempotency_key' => 'blind:'.$hand->id.':'.$player->user_id.':'.$blindType,
                    'metadata' => [
                        'hand_id' => $hand->id,
                        'player_id' => $player->id,
                        'blind_type' => $blindType,
                    ],
                ]);
            }
        }

        $hand->total_pot = $totalPot;
        // Current bet is the actual posted max, not the configured BB:
        // a short blind (all-in for less) sets a smaller price to match.
        $hand->current_bet = (int) $hand->players()->max('street_contribution');
        $hand->current_turn_user_id = $this->firstToActPreflop($hand, $orderedSeats, $dealerSeat, $bigSeat);
        $hand->save();

        TurnTimer::arm($hand->refresh(), $hand->current_turn_user_id);
    }

    /**
     * @param  array<int, int>  $orderedSeats  sorted ascending
     */
    private function firstToActPreflop(
        PokerHand $hand,
        array $orderedSeats,
        int $dealerSeat,
        int $bigSeat
    ): ?int {
        $count = count($orderedSeats);

        if ($count === 2) {
            // Heads-up preflop: dealer (SB) acts first, unless already all-in.
            $dealer = $hand->players()->where('seat', $dealerSeat)->first();
            if ($dealer !== null && $dealer->status === PokerHandPlayer::STATUS_ACTIVE) {
                return $dealer->user_id;
            }
            $other = $hand->players()->where('seat', '!=', $dealerSeat)->where('status', PokerHandPlayer::STATUS_ACTIVE)->first();

            return $other?->user_id;
        }

        $bigIdx = array_search($bigSeat, $orderedSeats, true);
        $nextSeat = $orderedSeats[($bigIdx + 1) % $count];

        // Skip players who posted a blind with their entire stack (all-in).
        for ($i = 0; $i < $count; $i++) {
            $player = $hand->players()->where('seat', $nextSeat)->first();
            if ($player !== null && $player->status === PokerHandPlayer::STATUS_ACTIVE) {
                return $player->user_id;
            }
            $idx = array_search($nextSeat, $orderedSeats, true);
            $nextSeat = $orderedSeats[($idx + 1) % $count];
        }

        return null;
    }
}
