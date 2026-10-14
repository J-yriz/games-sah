<?php

use App\Actions\Poker\CancelHand;
use App\Actions\Poker\Exceptions\IdempotencyConflictException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\InvalidSettlementException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException as RoomInvalidStateException;
use App\Actions\Rooms\ManageRoom;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

test('host can cancel before any betting with full blind refunds', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $stacksBefore = TableStack::where('room_id', $hand->room_id)->pluck('balance', 'user_id')->toArray();

    $result = app(CancelHand::class)->handle($host, $hand, 'wrong cards dealt', (string) Str::uuid());

    expect($result['hand']->status)->toBe(PokerHand::STATUS_CANCELLED)
        ->and($result['hand']->cancel_reason)->toBe('wrong cards dealt');

    // Every stack is exactly restored: post-blind balance + its contribution.
    foreach ($stacksBefore as $userId => $balance) {
        $contribution = (int) $hand->players()->where('user_id', $userId)->value('total_contribution');

        expect(TableStack::where('room_id', $hand->room_id)->where('user_id', $userId)->value('balance'))
            ->toBe($balance + $contribution);
    }

    $refunded = (int) ChipTransaction::where('from_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_REFUND)
        ->sum('amount');

    expect($refunded)->toBe(30);
});

test('cancel after betting refunds actual contributions per player', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    app(PlayerAction::class)->handle($p1, $hand, 'call', (string) Str::uuid());

    $result = app(CancelHand::class)->handle($host, $hand, 'table dispute', (string) Str::uuid());

    $byUser = collect($result['refunds'])->keyBy('user_id');

    expect($byUser[$host->id]['amount'])->toBe(100)
        ->and($byUser[$p1->id]['amount'])->toBe(100)
        ->and($byUser->has($p2->id))->toBeTrue()
        ->and($byUser[$p2->id]['amount'])->toBe(20);

    // Conservation: stacks back to 1000 each.
    foreach ([$host, $p1, $p2] as $pl) {
        expect(TableStack::where('room_id', $hand->room_id)->where('user_id', $pl->id)->value('balance'))->toBe(1000);
    }

    // History preserved: blinds + betting actions still recorded.
    expect(PokerAction::where('hand_id', $hand->id)->count())->toBeGreaterThanOrEqual(4);
});

test('same cancel key replays without double refund', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);
    $key = (string) Str::uuid();

    $first = app(CancelHand::class)->handle($host, $hand, 'misdeal', $key);
    $second = app(CancelHand::class)->handle($host, $hand, 'misdeal', $key);

    expect($second['replayed'])->toBeTrue()
        ->and((int) ChipTransaction::where('from_poker_hand_id', $hand->id)
            ->where('type', ChipTransaction::TYPE_REFUND)->sum('amount'))
        ->toBe((int) collect($first['refunds'])->sum('amount'));
});

test('same cancel key with a different reason conflicts', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);
    $key = (string) Str::uuid();

    app(CancelHand::class)->handle($host, $hand, 'misdeal', $key);
    app(CancelHand::class)->handle($host, $hand, 'other reason', $key);
})->throws(IdempotencyConflictException::class);

test('settled hands cannot be cancelled and cancelled hands cannot settle or act', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();

    $hand = startPokerHand($host, [$p1, $p2]);
    $hand = driveToShowdown($hand, $host, $p1, $p2);
    app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], (string) Str::uuid());

    try {
        app(CancelHand::class)->handle($host, $hand, 'too late', (string) Str::uuid());
        expect(false)->toBeTrue('settled cancel must fail');
    } catch (InvalidHandStateException) {
        expect(true)->toBeTrue();
    }

    $host2 = User::factory()->create();
    $q1 = User::factory()->create();
    $hand2 = startPokerHand($host2, [$q1]);
    app(CancelHand::class)->handle($host2, $hand2, 'misdeal', (string) Str::uuid());

    try {
        app(SettleHand::class)->handle($host2, $hand2, [0 => [$host2->id]], (string) Str::uuid());
        expect(false)->toBeTrue('settle after cancel must fail');
    } catch (InvalidHandStateException) {
        expect(true)->toBeTrue();
    }

    try {
        app(PlayerAction::class)->handle($host2, $hand2, 'check', (string) Str::uuid());
        expect(false)->toBeTrue('action after cancel must fail');
    } catch (InvalidHandStateException) {
        expect(true)->toBeTrue();
    }
});

test('non-host cannot cancel', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);

    app(CancelHand::class)->handle($p1, $hand, 'misdeal', (string) Str::uuid());
})->throws(InvalidSettlementException::class);

test('closing a room with an active hand is refused', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);

    app(ManageRoom::class)->close($host, $hand->room, 'completed');
})->throws(RoomInvalidStateException::class);
