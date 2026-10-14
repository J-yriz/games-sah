<?php

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\PlayerAction;
use App\Models\PokerHand;
use App\Models\User;
use Illuminate\Support\Str;

test('host opens flop after preflop completes', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    completePreflopNoRaise($hand, $host, $p1, $p2);

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_FLOP);

    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    expect($hand->status)->toBe(PokerHand::STATUS_FLOP)
        ->and($hand->street)->toBe(PokerHand::STREET_FLOP)
        ->and($hand->current_bet)->toBe(0)
        // First active left of dealer (seat 0) is seat 1 = p1.
        ->and($hand->current_turn_user_id)->toBe($p1->id);
});

test('full run to showdown through all host confirmations', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $hand = driveToShowdown($hand, $host, $p1, $p2);

    expect($hand->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN)
        ->and($hand->street)->toBe(PokerHand::STREET_SHOWDOWN);
});

test('non-host cannot open the next street', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    completePreflopNoRaise($hand, $host, $p1, $p2);

    app(AdvanceStreet::class)->handle($p1, $hand);
})->throws(InvalidHandStateException::class);

test('settled hand cannot be acted upon', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);

    // Fold-win settles immediately.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 500);
    app(PlayerAction::class)->handle($p1, $hand, 'fold', (string) Str::uuid());
    app(PlayerAction::class)->handle($p2, $hand, 'fold', (string) Str::uuid());

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_SETTLED);

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
})->throws(InvalidHandStateException::class);
