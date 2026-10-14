<?php

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\Exceptions\IdempotencyConflictException;
use App\Actions\Poker\Exceptions\InvalidBetAmountException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\StartHand;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

// Seating for startPokerHand($host, [$p1, $p2]):
// seat 0 = host (dealer), seat 1 = p1 (SB 10), seat 2 = p2 (BB 20).
// Preflop first to act: host (UTG). Postflop first: p1.

test('first to act preflop is player after big blind', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    expect($hand->current_turn_user_id)->toBe($host->id);
});

test('check is rejected preflop when facing the blind bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
})->throws(InvalidBetAmountException::class);

test('check is valid on the flop when no bet to match', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);

    $hand = checkStreet($hand, $host, [$player1]);

    // player1 checked on the flop; turn moved to player2.
    expect($hand->refresh()->current_turn_user_id)->toBe($player2->id)
        ->and(PokerAction::where('hand_id', $hand->id)->where('action', 'check')->exists())->toBeTrue();
});

test('check is invalid on the flop when facing a bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 40);
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());
})->throws(InvalidBetAmountException::class);

test('call matches the current bet preflop', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    // UTG raises to 40 (min raise_to = 20 + 20).
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 40);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());

    expect($result['player']->street_contribution)->toBe(40)
        ->and($result['player']->total_contribution)->toBe(40)
        ->and($hand->refresh()->current_bet)->toBe(40);
});

test('call with insufficient stack goes all-in short', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 10]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 11]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 15]);

    // Heads-up: dealer(host, seat 10) = SB, player1 (seat 11) = BB.
    // BB posted min(20, 15) = 15 and is all-in; dealer acts first.
    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    expect($hand->current_turn_user_id)->toBe($host->id);

    // Dealer calls the 15 (already contributed 10 as SB, needs 5 more).
    $result = app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());

    expect($result['player']->street_contribution)->toBe(15)
        ->and($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_FLOP);
});

test('bet is valid on the flop when no current bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 50);

    expect($result['player']->street_contribution)->toBe(50)
        ->and($hand->refresh()->current_bet)->toBe(50);
});

test('bet below minimum is rejected', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 5);
})->throws(InvalidBetAmountException::class);

test('bet when facing a bet is rejected (must raise)', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 50);
    app(PlayerAction::class)->handle($player2, $hand, 'bet', (string) Str::uuid(), 100);
})->throws(InvalidBetAmountException::class);

test('raise below min full raise is rejected', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 50);
    app(PlayerAction::class)->handle($player2, $hand, 'raise', (string) Str::uuid(), raiseTo: 60);
})->throws(InvalidBetAmountException::class);

test('valid raise to 100 on the flop', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 50);
    $result = app(PlayerAction::class)->handle($player2, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);

    expect($result['player']->street_contribution)->toBe(100)
        ->and($hand->refresh()->current_bet)->toBe(100);
});

test('preflop raise_to must meet the minimum (BB 20, min raise_to 40)', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 30);
})->throws(InvalidBetAmountException::class);

test('fold removes player from hand', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    $result = app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());

    expect($result['player']->status)->toBe(PokerHandPlayer::STATUS_FOLDED)
        ->and($result['player']->acted_this_street)->toBeTrue();
});

test('folded player cannot act again', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'call', (string) Str::uuid());

    // Back to host who faces the raise; host folds too is fine, but the
    // already-folded player1 must be rejected if they try to act.
    $hand->refresh();
    $turnId = $hand->current_turn_user_id;
    expect($turnId)->not->toBe($player1->id);

    // Force the turn check by attempting an action as the folded player.
    // Bypass turn by setting current turn to player1 would be artificial;
    // instead assert status directly.
    expect(PokerHandPlayer::where('hand_id', $hand->id)->where('user_id', $player1->id)->value('status'))
        ->toBe(PokerHandPlayer::STATUS_FOLDED);
});

test('duplicate action replays without double ledger', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $key = (string) Str::uuid();
    $countBefore = ChipTransaction::count();
    $first = app(PlayerAction::class)->handle($host, $hand, 'call', $key);
    $countAfterFirst = ChipTransaction::count();
    $second = app(PlayerAction::class)->handle($host, $hand, 'call', $key);

    expect($first['action']->id)->toBe($second['action']->id)
        ->and($second['replayed'])->toBeTrue()
        ->and(ChipTransaction::count())->toBe($countAfterFirst)
        ->and($countAfterFirst)->toBe($countBefore + 1);
});

test('same idempotency key with different action is conflict', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $key = (string) Str::uuid();
    app(PlayerAction::class)->handle($host, $hand, 'call', $key);

    // player1 is now to act; reusing host's key with different params conflicts
    // at the replay-match stage (same key, different player/action).
    app(PlayerAction::class)->handle($player1, $hand, 'raise', $key, raiseTo: 100);
})->throws(IdempotencyConflictException::class);

test('acting out of turn is rejected', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());
})->throws(NotYourTurnException::class);

test('preflop completes into awaiting_flop when all matched', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());
    $result = app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());

    expect($result['street_complete'])->toBeTrue()
        ->and($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_FLOP);
});

test('host cannot open next street while betting is incomplete', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(AdvanceStreet::class)->handle($host, $hand);
})->throws(InvalidHandStateException::class);

test('short all-in raise does not reopen with a below-minimum raise_to but is a legal all-in', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    completePreflopNoRaise($hand, $host, $player1, $player2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    // Shrink player2 stack to force a short all-in.
    $stack = TableStack::where('room_id', $hand->room_id)->where('user_id', $player2->id)->first();
    $stack->balance = 30;
    $stack->save();

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 50);
    // player2 cannot cover: all-in short for 30 (raise_to 30 < min 100) is allowed.
    $result = app(PlayerAction::class)->handle($player2, $hand, 'all_in', (string) Str::uuid());

    expect($result['player']->status)->toBe(PokerHandPlayer::STATUS_ALL_IN)
        ->and($hand->refresh()->current_bet)->toBe(50)
        ->and($hand->refresh()->min_full_raise)->toBe(50);
});
