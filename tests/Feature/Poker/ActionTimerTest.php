<?php

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\ProcessTimeouts;
use App\Actions\Poker\StartHand;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

function makeTimedRoom(User $host, array $players, int $timer = 60, int $extension = 0): Room
{
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
        'action_timer' => $timer,
        'action_timer_extension' => $extension,
    ]);

    foreach (array_merge([$host], $players) as $i => $player) {
        RoomMember::factory()->create([
            'room_id' => $room->id,
            'user_id' => $player->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'seat' => $i,
        ]);
        TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 1000]);
    }

    return $room;
}

function expireTurn(PokerHand $hand): PokerHand
{
    $hand->update(['turn_deadline_at' => now()->subMinute()]);

    return $hand->refresh();
}

test('deadline is armed when a hand starts', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1], 120);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    expect($hand->turn_deadline_at)->not->toBeNull()
        ->and($hand->turn_started_at)->not->toBeNull()
        ->and($hand->turn_extended)->toBeFalse();
});

test('no deadline is armed when the timer is off', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1], 0);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    expect($hand->turn_deadline_at)->toBeNull();
});

test('valid action re-arms the deadline for the next turn', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 120);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    $hand->update(['turn_deadline_at' => now()->addHour(), 'turn_started_at' => now()]);
    $manipulated = $hand->refresh()->turn_deadline_at;
    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());

    $hand->refresh();

    expect($hand->current_turn_user_id)->toBe($p1->id)
        ->and($hand->turn_deadline_at->notEqualTo($manipulated))->toBeTrue()
        ->and($hand->turn_deadline_at->isFuture())->toBeTrue();
});

test('expired free turn auto-checks and flags the action', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    completePreflopNoRaise($hand, $host, $p1, $p2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    // Flop, p1 to act with nothing to match: expiry must auto-check p1.
    expireTurn($hand);

    $summary = app(ProcessTimeouts::class)->handle($hand->id);

    expect($summary['acted'])->toBe(1);

    $auto = PokerAction::where('hand_id', $hand->id)->where('action', 'check')->latest('id')->first();

    expect($auto)->not->toBeNull()
        ->and($auto->metadata['timeout'])->toBeTrue()
        ->and($auto->user_id)->toBe($p1->id)
        ->and($hand->refresh()->current_turn_user_id)->toBe($p2->id);
});

test('expired facing turn auto-folds', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    // UTG raises; SB now faces a bet.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    expireTurn($hand->refresh());

    $summary = app(ProcessTimeouts::class)->handle($hand->id);

    expect($summary['acted'])->toBe(1);

    $auto = PokerAction::where('hand_id', $hand->id)->where('action', 'fold')->latest('id')->first();

    expect($auto)->not->toBeNull()
        ->and($auto->metadata['timeout'])->toBeTrue()
        ->and($auto->user_id)->toBe($p1->id);
});

test('second worker pass on the same turn is a no-op', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    completePreflopNoRaise($hand, $host, $p1, $p2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);
    expireTurn($hand);

    expect(app(ProcessTimeouts::class)->handle($hand->id)['acted'])->toBe(1);

    // Turn moved on with a fresh future deadline: nothing due.
    $again = app(ProcessTimeouts::class)->handle($hand->id);

    expect($again)->toBe(['processed' => 0, 'acted' => 0, 'extended' => 0, 'skipped' => 0]);
});

test('extension is granted once, then the next expiry acts', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60, 30);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    completePreflopNoRaise($hand, $host, $p1, $p2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);
    expireTurn($hand);

    expect(app(ProcessTimeouts::class)->handle($hand->id)['extended'])->toBe(1);
    expect($hand->refresh()->turn_extended)->toBeTrue();

    expireTurn($hand->refresh());

    expect(app(ProcessTimeouts::class)->handle($hand->id)['acted'])->toBe(1);
});

test('timeout never fires on settled or cancelled hands', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    $hand->update([
        'status' => PokerHand::STATUS_SETTLED,
        'current_turn_user_id' => $host->id,
        'turn_deadline_at' => now()->subHour(),
    ]);

    // The sweep only selects betting hands, so a terminal hand is not even
    // picked up — and must never gain timeout actions.
    expect(app(ProcessTimeouts::class)->handle($hand->id))
        ->toBe(['processed' => 0, 'acted' => 0, 'extended' => 0, 'skipped' => 0])
        ->and(PokerAction::where('hand_id', $hand->id)->count())->toBe(2);
});
