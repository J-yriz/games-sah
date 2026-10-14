<?php

use App\Actions\Poker\StartHand;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\LeaveRoom;
use App\Actions\Rooms\ManageRoom;
use App\Actions\Rooms\StandUp;
use App\Actions\Rooms\TakeSeat;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;

function makeSeatedRoom(User $host, array $players, int $maxPlayers = 8): Room
{
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'max_players' => $maxPlayers,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    $all = array_merge([$host], $players);
    foreach ($all as $i => $player) {
        RoomMember::factory()->create([
            'room_id' => $room->id,
            'user_id' => $player->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'seat' => null,
        ]);
        TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 1000]);
    }

    return $room;
}

test('automatic assignment takes the first free seat', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1]);

    $seatHost = app(TakeSeat::class)->handle($host, $room);
    $seatP1 = app(TakeSeat::class)->handle($p1, $room);

    expect($seatHost->seat)->toBe(0)->and($seatP1->seat)->toBe(1);
});

test('manual seat selection works when free', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1]);

    $member = app(TakeSeat::class)->handle($p1, $room, 4);

    expect($member->seat)->toBe(4);
});

test('seat outside table capacity is rejected', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1], 4);

    app(TakeSeat::class)->handle($p1, $room, 7);
})->throws(InvalidRoomStateException::class);

test('full table refuses new seats', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1], 2);

    app(TakeSeat::class)->handle($host, $room);
    app(TakeSeat::class)->handle($p1, $room);

    $late = User::factory()->create();
    RoomMember::factory()->create([
        'room_id' => $room->id, 'user_id' => $late->id,
        'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => null,
    ]);

    app(TakeSeat::class)->handle($late, $room);
})->throws(InvalidRoomStateException::class);

test('re-taking the current seat is idempotent', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1]);

    $first = app(TakeSeat::class)->handle($p1, $room, 2);
    $second = app(TakeSeat::class)->handle($p1, $room, 2);

    expect($first->id)->toBe($second->id)->and($second->seat)->toBe(2);
});

test('seat cannot change while seated in an active hand', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1, $p2]);

    app(TakeSeat::class)->handle($host, $room, 0);
    app(TakeSeat::class)->handle($p1, $room, 1);
    app(TakeSeat::class)->handle($p2, $room, 2);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    expect($hand->players()->count())->toBe(3);

    app(TakeSeat::class)->handle($p1, $room, 5);
})->throws(InvalidRoomStateException::class);

test('stand up is refused mid-hand but works after settle', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1]);

    app(TakeSeat::class)->handle($host, $room, 0);
    app(TakeSeat::class)->handle($p1, $room, 1);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    try {
        app(StandUp::class)->handle($p1, $room);
        expect(false)->toBeTrue('stand up mid-hand must be refused');
    } catch (InvalidRoomStateException) {
        expect(true)->toBeTrue();
    }

    // Finish the hand, then standing up works and the snapshot keeps the seat.
    $hand->status = PokerHand::STATUS_SETTLED;
    $hand->save();

    $member = app(StandUp::class)->handle($p1, $room);

    expect($member->seat)->toBeNull()
        ->and(PokerHandPlayer::where('hand_id', $hand->id)->where('user_id', $p1->id)->value('seat'))->toBe(1);
});

test('leave and host-remove are refused mid-hand', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeSeatedRoom($host, [$p1, $p2]);

    app(TakeSeat::class)->handle($host, $room, 0);
    app(TakeSeat::class)->handle($p1, $room, 1);
    app(TakeSeat::class)->handle($p2, $room, 2);

    app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    // Zero the stack so the only blocker is the active hand.
    TableStack::where('room_id', $room->id)->where('user_id', $p1->id)->update(['balance' => 0]);

    try {
        app(LeaveRoom::class)->handle($p1, $room);
        expect(false)->toBeTrue('leave mid-hand must be refused');
    } catch (InvalidRoomStateException) {
        expect(true)->toBeTrue();
    }

    TableStack::where('room_id', $room->id)->where('user_id', $p2->id)->update(['balance' => 0]);

    try {
        app(ManageRoom::class)->removePlayer($host, $room, $p2);
        expect(false)->toBeTrue('remove mid-hand must be refused');
    } catch (InvalidRoomStateException) {
        expect(true)->toBeTrue();
    }
});
