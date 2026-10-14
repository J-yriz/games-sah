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

    $ledger = ChipTransaction::where('to_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_BLIND_POST)
        ->get();

    expect($ledger)->toHaveCount(2);
});

test('blinds reduce table stacks atomically', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 20]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 21]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 22]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    // SB seat 21 posts 10, BB seat 22 posts 20; dealer untouched.
    expect(TableStack::where('room_id', $room->id)->where('user_id', $player1->id)->value('balance'))->toBe(990)
        ->and(TableStack::where('room_id', $room->id)->where('user_id', $player2->id)->value('balance'))->toBe(980)
        ->and(TableStack::where('room_id', $room->id)->where('user_id', $host->id)->value('balance'))->toBe(1000)
        ->and((int) TableStack::where('room_id', $room->id)->sum('balance'))->toBe(2970)
        ->and($hand->total_pot)->toBe(30);
});

test('non-host cannot start a hand', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 30]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 31]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 32]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    app(StartHand::class)->handle($player1, $room, (string) Str::uuid());
})->throws(InvalidHandStateException::class);

test('heads-up assigns dealer as small blind and dealer acts first', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 40]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 41]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    $dealer = $hand->players()->where('is_dealer', true)->first();
    $sb = $hand->players()->where('is_small_blind', true)->first();
    $bb = $hand->players()->where('is_big_blind', true)->first();

    expect($dealer->user_id)->toBe($host->id)
        ->and($sb->user_id)->toBe($host->id)
        ->and($bb->user_id)->toBe($player1->id)
        ->and($hand->current_turn_user_id)->toBe($host->id);
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
