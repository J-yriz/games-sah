<?php

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('same seat number may be used in two different rooms', function () {
    $roomA = Room::factory()->create();
    $roomB = Room::factory()->create();

    RoomMember::factory()->create([
        'room_id' => $roomA->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 0,
    ]);

    $memberB = RoomMember::factory()->create([
        'room_id' => $roomB->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 0,
    ]);

    expect($memberB->seat)->toBe(0);
});

test('two members cannot share a seat in the same room', function () {
    $room = Room::factory()->create();

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 3,
    ]);

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 3,
    ]);
})->throws(QueryException::class);

test('membership of a user stays unique within a room', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $user->id, 'seat' => 1]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $user->id, 'seat' => 2]);
})->throws(QueryException::class);

test('moving a member onto a taken seat is rejected, onto a free seat works', function () {
    $room = Room::factory()->create();

    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 0,
    ]);
    $mover = RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => 1,
    ]);

    // Nested transaction = savepoint: the expected violation must not poison
    // the outer test transaction (PostgreSQL aborts on first error).
    expect(fn () => DB::transaction(fn () => $mover->update(['seat' => 0])))->toThrow(QueryException::class);

    $mover->update(['seat' => 5]);

    expect($mover->refresh()->seat)->toBe(5);
});

test('null seats stay allowed for unseated members', function () {
    $room = Room::factory()->create();

    $a = RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => null,
    ]);
    $b = RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'seat' => null,
    ]);

    expect($a->seat)->toBeNull()->and($b->seat)->toBeNull();
});

test('rapid seat claims in one room leave exactly one winner', function () {
    $room = Room::factory()->create();

    $wins = 0;
    $conflicts = 0;

    foreach (range(0, 4) as $i) {
        try {
            // Savepoint per attempt so a rejected claim does not poison the
            // outer test transaction on PostgreSQL.
            DB::transaction(fn () => RoomMember::factory()->create([
                'room_id' => $room->id,
                'user_id' => User::factory()->create()->id,
                'seat' => 7,
            ]));
            $wins++;
        } catch (QueryException) {
            $conflicts++;
        }
    }

    expect($wins)->toBe(1)
        ->and($conflicts)->toBe(4)
        ->and(RoomMember::where('room_id', $room->id)->where('seat', 7)->count())->toBe(1);
});
