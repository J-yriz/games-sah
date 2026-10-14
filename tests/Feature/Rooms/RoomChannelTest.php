<?php

use App\Broadcasting\RoomChannel;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;

test('active members can join the room channel', function () {
    $member = RoomMember::factory()->create();

    $result = app(RoomChannel::class)->join($member->user, $member->room_id);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe($member->user_id)
        ->and($result['info']['username'])->toBe($member->user->username)
        ->and($result)->not->toHaveKey('email');
});

test('outsiders cannot join the room channel', function () {
    $room = Room::factory()->create();
    $outsider = User::factory()->create();

    expect(app(RoomChannel::class)->join($outsider, $room->id))->toBe([]);
});

test('removed members lose channel access', function () {
    $member = RoomMember::factory()->create(['status' => RoomMember::STATUS_REMOVED]);

    expect(app(RoomChannel::class)->join($member->user, $member->room_id))->toBe([]);
});
