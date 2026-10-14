<?php

use App\Actions\Chips\CashOutFromRoom;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Actions\Rooms\ManageRoom;
use App\Models\PokerHand;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

test('settled hand appears in room history with winners', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $roomId = $hand->room_id;
    $hand = driveToShowdown($hand, $host, $p1, $p2);
    app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], (string) Str::uuid());

    $response = $this->actingAs($host)->get(route('rooms.hands', $roomId));

    $response->assertOk()
        ->assertSee('Hand #1', false)
        ->assertSee((string) $host->username, false);
});

test('hand detail shows seats, actions, pots and ledger', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $roomId = $hand->room_id;
    $hand = driveToShowdown($hand, $host, $p1, $p2);
    app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], (string) Str::uuid());

    $response = $this->actingAs($p1)->get(route('rooms.hands.show', [$roomId, $hand->id]));

    $response->assertOk()
        ->assertSee((string) $p2->username, false)
        ->assertSee('Action sequence', false)
        ->assertSee('Pots &amp; payouts', false)
        ->assertSee('Ledger', false);
});

test('outsiders cannot read private hand history', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $stranger = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);
    $roomId = $hand->room_id;

    $this->actingAs($stranger)->get(route('rooms.hands', $roomId))->assertForbidden();
    $this->actingAs($stranger)->get(route('rooms.hands.show', [$roomId, $hand->id]))->assertForbidden();
});

test('history stays readable after the room is closed', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);
    $roomId = $hand->room_id;

    // Settle quickly via fold-win, then cash everyone out and close.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 500);
    app(PlayerAction::class)->handle($p1, $hand, 'fold', (string) Str::uuid());
    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_SETTLED);

    $room = $hand->room;
    foreach ([$host, $p1] as $pl) {
        $stack = TableStack::where('room_id', $roomId)->where('user_id', $pl->id)->first();
        app(CashOutFromRoom::class)->handle($pl, $room, (int) $stack->balance, (string) Str::uuid());
    }
    app(ManageRoom::class)->close($host, $room->refresh(), 'completed');

    // Fold-win left the hand settled; history must still render.
    $this->actingAs($host)->get(route('rooms.hands', $roomId))->assertOk();
});
