<?php

use App\Broadcasting\PokerHandChannel;
use App\Broadcasting\RoomChannel;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;

test('room channel admits active members only', function () {
    $host = User::factory()->create();
    $stranger = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    RoomMember::factory()->create([
        'room_id' => $room->id, 'user_id' => $host->id,
        'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE,
    ]);

    expect(app(RoomChannel::class)->join($host, $room->id))->not->toBeEmpty()
        ->and(app(RoomChannel::class)->join($stranger, $room->id))->toBeEmpty();
});

test('removed members lose room channel access', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $member = RoomMember::factory()->create([
        'room_id' => $room->id, 'user_id' => $host->id,
        'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_REMOVED,
    ]);

    expect(app(RoomChannel::class)->join($member->user, $room->id))->toBeEmpty();
});

test('poker hand channel admits room members, rejects strangers', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $stranger = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);

    expect(app(PokerHandChannel::class)->join($host, $hand->id))->toBeTrue()
        ->and(app(PokerHandChannel::class)->join($p1, $hand->id))->toBeTrue()
        ->and(app(PokerHandChannel::class)->join($stranger, $hand->id))->toBeFalse();
});

test('poker hand channel rejects unknown hands', function () {
    $user = User::factory()->create();

    expect(app(PokerHandChannel::class)->join($user, 999999999))->toBeFalse();
});
