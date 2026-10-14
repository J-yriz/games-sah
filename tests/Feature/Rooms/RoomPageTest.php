<?php

use App\Models\ChipTransaction;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

function fundRoomUser(User $user, int $balance): void
{
    $wallet = $user->wallet()->create(['balance' => $balance]);

    if ($balance > 0) {
        ChipTransaction::create([
            'from_wallet_id' => null,
            'to_wallet_id' => $wallet->id,
            'type' => ChipTransaction::TYPE_INITIAL_GRANT,
            'amount' => $balance,
            'idempotency_key' => 'initial-grant:'.$user->id,
            'metadata' => ['user_id' => $user->id, 'grant' => 'test'],
        ]);
    }
}

test('guests cannot open rooms pages', function () {
    $this->get(route('rooms.index'))->assertRedirect(route('login'));
});

test('non-members receive 403 on the lobby', function () {
    $host = User::factory()->create();
    $outsider = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($outsider)->get(route('rooms.show', $room))->assertForbidden();
});

test('members can open the lobby', function () {
    $member = RoomMember::factory()->create();

    $this->actingAs($member->user)->get(route('rooms.show', $member->room_id))->assertOk();
});

test('create room flow redirects to the lobby', function () {
    $host = User::factory()->create();
    fundRoomUser($host, 10_000);

    $this->actingAs($host);

    Livewire::test('pages::rooms')
        ->set('createName', 'Saturday Poker')
        ->set('createMaxPlayers', '6')
        ->set('createMinBuyIn', '100')
        ->set('createMaxBuyIn', '')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Room::where('name', 'Saturday Poker')->exists())->toBeTrue();
});

test('join room flow with a valid code redirects to the lobby', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($player);

    Livewire::test('pages::rooms')
        ->set('joinCode', $room->invite_code)
        ->call('join')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($room->members()->where('user_id', $player->id)->exists())->toBeTrue();
});

test('join room flow with a bad code shows an error', function () {
    $player = User::factory()->create();

    $this->actingAs($player);

    Livewire::test('pages::rooms')
        ->set('joinCode', 'BOGUS999')
        ->call('join')
        ->assertHasErrors(['joinCode']);
});

test('lobby buy-in flow moves chips with confirmation', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    fundRoomUser($player, 10_000);
    $room = Room::factory()->create(['host_user_id' => $host->id, 'min_buy_in' => 100, 'max_buy_in' => 5_000]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player->id]);

    $this->actingAs($player);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->call('startMove', 'buy_in')
        ->set('moveAmount', '1000')
        ->call('reviewMove')
        ->assertHasNoErrors()
        ->assertSet('confirmingMove', true)
        ->call('confirmMove')
        ->assertHasNoErrors();

    expect($player->wallet->refresh()->balance)->toBe(9_000);
});

test('lobby cash-out flow returns chips', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    fundRoomUser($player, 10_000);
    $room = Room::factory()->create(['host_user_id' => $host->id, 'min_buy_in' => 100]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player->id]);

    $this->actingAs($player);

    $page = Livewire::test('pages::rooms.show', ['room' => $room])
        ->call('startMove', 'buy_in')
        ->set('moveAmount', '1000')
        ->call('reviewMove')
        ->call('confirmMove')
        ->assertHasNoErrors();

    $page->call('startMove', 'cash_out')
        ->set('moveAmount', '400')
        ->call('reviewMove')
        ->call('confirmMove')
        ->assertHasNoErrors();

    expect($player->wallet->refresh()->balance)->toBe(9_400);
});

test('host controls are hidden from players', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player->id]);

    $this->actingAs($player);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->assertDontSee('Host controls');
});
