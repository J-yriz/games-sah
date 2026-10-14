<?php

use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\RoomFullException;
use App\Actions\Rooms\Exceptions\RoomNotFoundException;
use App\Actions\Rooms\Exceptions\StackNotEmptyException;
use App\Actions\Rooms\JoinRoom;
use App\Actions\Rooms\LeaveRoom;
use App\Actions\Rooms\ManageRoom;
use App\Events\RoomStateUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function makeRoom(User $host, array $overrides = []): Room
{
    return app(CreateRoom::class)->handle($host, array_merge([
        'name' => 'Friday Poker',
        'max_players' => 4,
        'min_buy_in' => 100,
        'max_buy_in' => 5_000,
    ], $overrides));
}

test('host can create a room and becomes its host member', function () {
    $host = User::factory()->create();

    $room = makeRoom($host);

    expect($room->invite_code)->toHaveLength(8)
        ->and($room->status)->toBe(Room::STATUS_WAITING)
        ->and($room->host_user_id)->toBe($host->id);

    $member = $room->members()->where('user_id', $host->id)->first();

    expect($member->role)->toBe(Room::ROLE_HOST)
        ->and($member->status)->toBe(RoomMember::STATUS_ACTIVE);
});

test('invite codes are unique across rooms', function () {
    $host = User::factory()->create();

    $codes = collect(range(1, 10))->map(fn () => makeRoom($host)->invite_code);

    expect($codes->unique()->count())->toBe(10);
});

test('users can host rooms without email verification', function () {
    $host = User::factory()->unverified()->create();

    $room = app(CreateRoom::class)->handle($host, [
        'name' => 'Friday Poker', 'max_players' => 4, 'min_buy_in' => 100,
    ]);

    expect($room->host_user_id)->toBe($host->id);
});

test('join with a valid code adds an active player', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);

    $member = app(JoinRoom::class)->handle($player, $room->invite_code);

    expect($member->role)->toBe(Room::ROLE_PLAYER)
        ->and($member->status)->toBe(RoomMember::STATUS_ACTIVE);
});

test('join is idempotent for active members', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);

    $first = app(JoinRoom::class)->handle($player, $room->invite_code);
    $second = app(JoinRoom::class)->handle($player, strtolower($room->invite_code));

    expect($second->id)->toBe($first->id)
        ->and(RoomMember::where('room_id', $room->id)->where('user_id', $player->id)->count())->toBe(1);
});

test('unknown invite codes are rejected', function () {
    $player = User::factory()->create();

    app(JoinRoom::class)->handle($player, 'NOPE1234');
})->throws(RoomNotFoundException::class);

test('full rooms reject new joins', function () {
    $host = User::factory()->create();
    $room = makeRoom($host, ['max_players' => 2]);
    app(JoinRoom::class)->handle(User::factory()->create(), $room->invite_code);

    app(JoinRoom::class)->handle(User::factory()->create(), $room->invite_code);
})->throws(RoomFullException::class);

test('joining an active table is rejected', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(ManageRoom::class)->start($host, $room);

    app(JoinRoom::class)->handle($player, $room->invite_code);
})->throws(InvalidRoomStateException::class);

test('player can leave with an empty stack', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle($player, $room->invite_code);

    app(LeaveRoom::class)->handle($player, $room);

    expect($room->members()->where('user_id', $player->id)->first()->status)
        ->toBe(RoomMember::STATUS_LEFT);
});

test('player with chips must cash out before leaving', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle($player, $room->invite_code);
    TableStack::create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 500]);

    app(LeaveRoom::class)->handle($player, $room);
})->throws(StackNotEmptyException::class);

test('host cannot leave while players remain', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle(User::factory()->create(), $room->invite_code);

    app(LeaveRoom::class)->handle($host, $room);
})->throws(InvalidRoomStateException::class);

test('non-members cannot leave', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);

    app(LeaveRoom::class)->handle(User::factory()->create(), $room);
})->throws(NotMemberException::class);

test('policy hides room state from non-members', function () {
    $host = User::factory()->create();
    $outsider = User::factory()->create();
    $room = makeRoom($host);

    expect(Gate::forUser($host)->allows('view', $room))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $room))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('manage', $room))->toBeFalse()
        ->and(Gate::forUser($host)->allows('manage', $room))->toBeTrue();
});

test('host can update settings within member bounds', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);

    $updated = app(ManageRoom::class)->updateSettings($host, $room, [
        'name' => 'Saturday Poker',
        'max_players' => 6,
        'min_buy_in' => 200,
        'max_buy_in' => null,
    ]);

    expect($updated->name)->toBe('Saturday Poker')
        ->and($updated->max_players)->toBe(6);
});

test('host cannot shrink capacity below active members', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle(User::factory()->create(), $room->invite_code);

    app(ManageRoom::class)->updateSettings($host, $room, [
        'name' => 'Friday Poker',
        'max_players' => 1,
        'min_buy_in' => 100,
        'max_buy_in' => null,
    ]);
})->throws(ValidationException::class);

test('non-hosts cannot manage rooms', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle($player, $room->invite_code);

    app(ManageRoom::class)->start($player, $room);
})->throws(AuthorizationException::class);

test('closing requires empty stacks', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle($player, $room->invite_code);
    TableStack::create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 100]);

    app(ManageRoom::class)->close($host, $room);
})->throws(StackNotEmptyException::class);

test('host can remove a player with an empty stack', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle($player, $room->invite_code);

    app(ManageRoom::class)->removePlayer($host, $room, $player);

    expect($room->members()->where('user_id', $player->id)->first()->status)
        ->toBe(RoomMember::STATUS_REMOVED);
});

test('room events fire after commit only', function () {
    Event::fake([RoomStateUpdated::class]);

    $host = User::factory()->create();
    $room = makeRoom($host);
    app(JoinRoom::class)->handle(User::factory()->create(), $room->invite_code);

    Event::assertDispatched(RoomStateUpdated::class, fn ($e) => $e->roomId === $room->id);
});
