<?php

use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\StartHand;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

test('host can start a hand with minimum two seated players', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
        'min_raise_multiplier' => 2,
    ]);

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $host->id,
        'role' => Room::ROLE_HOST,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 0,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player1->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 1,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player2->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 2,
    ]);

    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    expect($hand->status)->toBe(PokerHand::STATUS_PREFLOP)
        ->and($hand->players()->count())->toBe(3)
        ->and($hand->total_pot)->toBe(30)
        ->and($hand->current_bet)->toBe(20);
});

test('hand cannot start with fewer than 2 eligible players', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);

    app(StartHand::class)->handle($host, $room, (string) Str::uuid());
})->throws(InvalidHandStateException::class);

test('duplicate start hand request replays', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $host->id,
        'role' => Room::ROLE_HOST,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 0,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player1->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 1,
    ]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);

    $key = (string) Str::uuid();
    $first = app(StartHand::class)->handle($host, $room, $key);
    $second = app(StartHand::class)->handle($host, $room, $key);

    expect($first->id)->toBe($second->id);
});

test('small blind and big blind are posted and recorded in ledger', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $host->id,
        'role' => Room::ROLE_HOST,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 0,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player1->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 1,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player2->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 2,
    ]);

    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    $blinds = PokerAction::where('hand_id', $hand->id)
        ->where('action', PokerAction::ACTION_POST_BLIND)
        ->get();

    expect($blinds)->toHaveCount(2);

    $ledger = ChipTransaction::where('from_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_BLIND_POST)
        ->get();

    expect($ledger)->toHaveCount(2);
});

test('dealer button rotates', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $host->id,
        'role' => Room::ROLE_HOST,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 0,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player1->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 1,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $player2->id,
        'role' => Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 2,
    ]);

    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand1 = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    expect($hand1->dealer_seat)->toBe(0);

    $hand1->status = PokerHand::STATUS_SETTLED;
    $hand1->save();

    $hand2 = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    expect($hand2->dealer_seat)->toBe(1);
});
