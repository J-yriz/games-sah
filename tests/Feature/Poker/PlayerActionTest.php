<?php

use App\Actions\Poker\Exceptions\IdempotencyConflictException;
use App\Actions\Poker\Exceptions\InvalidActionException;
use App\Actions\Poker\Exceptions\InvalidBetAmountException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\StartHand;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

test('first to act is player after big blind', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    expect($hand->current_turn_user_id)->toBe($host->id);
});

test('check is valid when no bet to match', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $result = app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());

    expect($result['player']->acted_this_street)->toBeTrue()
        ->and($hand->refresh()->current_bet)->toBe(20)
        ->and(PokerAction::where('hand_id', $hand->id)->where('action', 'check')->exists())->toBeTrue();
});

test('check is invalid when facing a bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 40);

    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
})->throws(InvalidBetAmountException::class);

test('call matches the current bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 40);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());

    expect($result['player']->street_contribution)->toBe(40)
        ->and($result['player']->total_contribution)->toBe(40)
        ->and($hand->refresh()->current_bet)->toBe(40);
});

test('call with insufficient stack goes all-in', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 0]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 1]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 15]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 40);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());

    expect($result['player']->street_contribution)->toBe(15)
        ->and($result['player']->status)->toBe(PokerHandPlayer::STATUS_ALL_IN);
});

test('bet is valid when no current bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $result = app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    expect($result['player']->street_contribution)->toBe(50)
        ->and($hand->refresh()->current_bet)->toBe(50)
        ->and($hand->min_full_raise)->toBe(50);
});

test('bet below minimum is rejected', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 5);
})->throws(InvalidBetAmountException::class);

test('bet when facing a bet is rejected (must raise)', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    app(PlayerAction::class)->handle($player1, $hand, 'bet', (string) Str::uuid(), 100);
})->throws(InvalidBetAmountException::class);

test('raise must be at least min_full_raise', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    app(PlayerAction::class)->handle($player1, $hand, 'raise', (string) Str::uuid(), raiseTo: 60);
})->throws(InvalidBetAmountException::class);

test('valid raise to 100 (min full raise = 50, current bet = 50, so min raise to = 100)', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);

    expect($result['player']->street_contribution)->toBe(100)
        ->and($hand->refresh()->current_bet)->toBe(100)
        ->and($hand->min_full_raise)->toBe(50);
});

test('fold removes player from hand', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());

    expect($result['player']->status)->toBe(PokerHandPlayer::STATUS_FOLDED)
        ->and($result['player']->acted_this_street)->toBeTrue();
});

test('folded player cannot act again', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 50);

    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());

    app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());
})->throws(InvalidActionException::class);

test('duplicate action replays', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $key = (string) Str::uuid();
    $first = app(PlayerAction::class)->handle($host, $hand, 'check', $key);
    $second = app(PlayerAction::class)->handle($host, $hand, 'check', $key);

    expect($first['action']->id)->toBe($second['action']->id)
        ->and($second['replayed'])->toBeTrue();
});

test('same idempotency key with different action is conflict', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    $key = (string) Str::uuid();
    app(PlayerAction::class)->handle($host, $hand, 'check', $key);

    app(PlayerAction::class)->handle($host, $hand, 'bet', $key, 50);
})->throws(IdempotencyConflictException::class);

test('acting out of turn is rejected', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
})->throws(NotYourTurnException::class);

test('street completes when all active players acted and matched bet', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());

    $result = app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

    expect($result['street_complete'])->toBeTrue()
        ->and($hand->refresh()->street)->toBe(PokerHand::STREET_FLOP)
        ->and($hand->status)->toBe(PokerHand::STREET_FLOP);
});
