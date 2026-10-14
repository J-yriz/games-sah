<?php

use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\TableStack;
use App\Models\User;
use App\Support\PotCalculator;
use Illuminate\Support\Str;

// NOTE: These serialization tests run on SQLite. True row-level
// concurrency must still be validated on PostgreSQL (PRD 14): the
// environment here has no pgsql driver/server. All mutating poker
// operations take lockForUpdate() on the hand first, in a consistent
// order (hand -> players -> stacks), so concurrent requests against
// the same hand serialize on the hand row under PostgreSQL.

test('second action on the same turn is rejected after the turn advances', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);

    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());

    // Host already acted; turn moved on. A repeated call must fail.
    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());
})->throws(NotYourTurnException::class);

test('out-of-turn action loses the race to the rightful player', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);

    // p2 tries to act while it is host (UTG) turn.
    app(PlayerAction::class)->handle($p2, $hand, 'call', (string) Str::uuid());
})->throws(NotYourTurnException::class);

test('concurrent settlement attempts: second with a different key is rejected without double payout', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    // Measured after blinds were posted (10 + 20 escrowed), so stacks read
    // 2970 here; a correct settlement returns everything to 3000.
    $totalAfterBlinds = (int) TableStack::where('room_id', $hand->room_id)->sum('balance');

    $hand = driveToShowdown($hand, $host, $p1, $p2);

    app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], (string) Str::uuid());

    // Conservation: all escrowed chips return to stacks.
    expect((int) TableStack::where('room_id', $hand->room_id)->sum('balance'))->toBe($totalAfterBlinds + 30);

    // A "concurrent" second settlement with a different idempotency key must
    // fail loudly instead of paying twice.
    app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], (string) Str::uuid());
})->throws(InvalidHandStateException::class, 'already settled');

test('action racing a settlement loses once the hand is settled', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);

    // Fold-win settles the hand immediately.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 500);
    app(PlayerAction::class)->handle($p1, $hand, 'fold', (string) Str::uuid());
    app(PlayerAction::class)->handle($p2, $hand, 'fold', (string) Str::uuid());

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_SETTLED);

    // Any further betting action must be rejected.
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
})->throws(InvalidHandStateException::class);

test('full-hand ledger reconciliation: contributions equal payouts', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $roomId = $hand->room_id;
    // Measured after blinds (30 escrowed): reads 2970; full settlement must
    // restore the 3000 initially granted to the three stacks.
    $stackAfterBlinds = (int) TableStack::where('room_id', $roomId)->sum('balance');

    $hand = driveToShowdown($hand, $host, $p1, $p2);

    // Blinds (30) + preflop completions (host 20 call, p1 10 call) = 60.
    $contrib = (int) ChipTransaction::where('to_poker_hand_id', $hand->id)->sum('amount');
    expect($contrib)->toBe(60)->and($hand->refresh()->total_pot)->toBe(60);

    $pots = PotCalculator::calculate(
        $hand->players()->get()->map(fn ($p) => [
            'user_id' => $p->user_id, 'seat' => $p->seat,
            'total' => (int) $p->total_contribution,
            'folded' => $p->status === 'folded',
        ])->toArray(),
        (int) $hand->dealer_seat
    );
    expect(array_sum(array_column($pots['pots'], 'amount')))->toBe(60);

    $result = app(SettleHand::class)->handle($host, $hand, [0 => [$p1->id]], (string) Str::uuid());

    $payout = (int) ChipTransaction::where('from_poker_hand_id', $hand->id)->sum('amount');
    $paidOut = array_sum(array_column($result['distributions'][0]['winners'], 'amount'));

    expect($payout)->toBe($contrib)
        ->and($paidOut)->toBe(60)
        ->and((int) TableStack::where('room_id', $roomId)->sum('balance'))->toBe($stackAfterBlinds + 30);
});
