<?php

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

test('seat conflict surfaces a friendly error without internals', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'max_players' => 4,
    ]);

    foreach ([$host, $p1] as $i => $player) {
        RoomMember::factory()->create([
            'room_id' => $room->id,
            'user_id' => $player->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'seat' => $i === 0 ? 0 : null,
        ]);
    }

    $this->actingAs($p1);

    // Seat 0 is taken; the unique violation must be translated, never shown.
    Livewire::test('pages::rooms.show', ['room' => $room])
        ->call('sitDown', 0)
        ->assertSee('just taken')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('room_members_room_seat_unique');
});

test('unexpected failures render a generic message', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'max_players' => 4,
    ]);
    RoomMember::factory()->create([
        'room_id' => $room->id,
        'user_id' => $host->id,
        'role' => Room::ROLE_HOST,
        'status' => RoomMember::STATUS_ACTIVE,
        'seat' => 0,
    ]);

    $this->actingAs($host);

    // Out-of-range seat triggers a domain error with a curated message.
    Livewire::test('pages::rooms.show', ['room' => $room])
        ->call('sitDown', 99)
        ->assertSee('outside the table capacity')
        ->assertDontSee('SQLSTATE');
});
