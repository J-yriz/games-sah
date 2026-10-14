<?php

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\StartHand;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function startPokerHand(User $host, array $players): PokerHand
{
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
        'min_raise_multiplier' => 2,
    ]);

    $allPlayers = array_merge([$host], $players);

    foreach ($allPlayers as $i => $player) {
        RoomMember::factory()->create([
            'room_id' => $room->id,
            'user_id' => $player->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'seat' => $i,
        ]);
        TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 1000]);
    }

    return app(StartHand::class)->handle($host, $room, (string) Str::uuid());
}

/**
 * Complete preflop with no raises (3-handed default seating):
 * UTG calls, SB completes, BB checks. Ends in awaiting_flop.
 */
function completePreflopNoRaise(PokerHand $hand, User $utg, User $sb, User $bb): PokerHand
{
    app(PlayerAction::class)->handle($utg, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($sb, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($bb, $hand, 'check', (string) Str::uuid());

    return $hand->refresh();
}

/**
 * Host opens the next street, then every active player checks.
 * Assumes all active players can check (no bets yet on the street).
 */
function checkStreet(PokerHand $hand, User $host, array $playersInTurnOrder): PokerHand
{
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    // Check whoever's turn it is, up to 10 actions (covers reordering after
    // folds). Only players in $playersInTurnOrder are allowed to act.
    $allowed = array_map(fn ($u) => $u->id, $playersInTurnOrder);

    for ($i = 0; $i < 10; $i++) {
        $fresh = $hand->refresh();
        if (! in_array($fresh->status, [
            PokerHand::STATUS_FLOP,
            PokerHand::STATUS_TURN,
            PokerHand::STATUS_RIVER,
            PokerHand::STATUS_PREFLOP,
        ], true)) {
            break;
        }
        $turnId = $fresh->current_turn_user_id;
        if ($turnId === null || ! in_array($turnId, $allowed, true)) {
            break;
        }
        $player = null;
        foreach ($playersInTurnOrder as $candidate) {
            if ($candidate->id === $turnId) {
                $player = $candidate;
                break;
            }
        }
        if ($player === null) {
            break;
        }
        app(PlayerAction::class)->handle($player, $fresh, 'check', (string) Str::uuid());
        $hand = $fresh->refresh();
    }

    return $hand->refresh();
}

/**
 * Drive a 3-handed hand (host=dealer seat 0, p1 seat 1, p2 seat 2) from
 * preflop to awaiting_showdown with no raises.
 */
function driveToShowdown(PokerHand $hand, User $host, User $p1, User $p2): PokerHand
{
    completePreflopNoRaise($hand, $host, $p1, $p2);
    // Flop/turn/river first-to-act order: p1, p2, host.
    $hand = checkStreet($hand, $host, [$p1, $p2, $host]);
    $hand = checkStreet($hand, $host, [$p1, $p2, $host]);
    $hand = checkStreet($hand, $host, [$p1, $p2, $host]);

    return $hand->refresh();
}
