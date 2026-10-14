<?php

use App\Actions\AccountBlockedException;
use App\Actions\Chips\CashOutFromRoom;
use App\Actions\CloseAccount;
use App\Actions\Poker\PlayerAction;
use App\Actions\Rooms\LeaveRoom;
use App\Models\ChipTransaction;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;

test('funded wallets are preserved via deactivation, never destroyed', function () {
    $user = User::factory()->create();
    fundLedgerUser($user, 500);

    $result = app(CloseAccount::class)->handle($user);

    expect($result['mode'])->toBe('deactivated')
        ->and($user->refresh()->wallet->balance)->toBe(500)
        ->and(ChipTransaction::where('to_wallet_id', $user->wallet->id)->count())->toBeGreaterThan(0);
});

test('closure is blocked while a table stack holds chips', function () {
    $host = User::factory()->create();
    $user = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id, 'status' => Room::STATUS_ACTIVE]);
    RoomMember::factory()->create([
        'room_id' => $room->id, 'user_id' => $user->id,
        'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 0,
    ]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $user->id, 'balance' => 50]);

    app(CloseAccount::class)->handle($user);
})->throws(AccountBlockedException::class);

test('footprint-free accounts are hard deleted', function () {
    $user = User::factory()->create();

    $result = app(CloseAccount::class)->handle($user);

    expect($result['mode'])->toBe('deleted')
        ->and(User::find($user->id))->toBeNull();
});

test('accounts with settled history are deactivated with ledger intact', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();

    $hand = startPokerHand($host, [$p1]);
    $roomId = $hand->room_id;

    // Fold-win settles, then cash out and leave so nothing live remains.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 500);
    app(PlayerAction::class)->handle($p1, $hand, 'fold', (string) Str::uuid());

    $room = $hand->room;
    foreach ([$host, $p1] as $pl) {
        $stack = TableStack::where('room_id', $roomId)->where('user_id', $pl->id)->first();
        app(CashOutFromRoom::class)->handle($pl, $room, (int) $stack->balance, (string) Str::uuid());
    }
    app(LeaveRoom::class)->handle($p1, $room->refresh());

    $ledgerBefore = ChipTransaction::where('to_poker_hand_id', $hand->id)->count()
        + ChipTransaction::where('from_poker_hand_id', $hand->id)->count();

    $result = app(CloseAccount::class)->handle($p1->refresh());

    expect($result['mode'])->toBe('deactivated')
        ->and(User::find($p1->id))->not->toBeNull()
        ->and(PokerHandPlayer::where('hand_id', $hand->id)->where('user_id', $p1->id)->exists())->toBeTrue();

    $ledgerAfter = ChipTransaction::where('to_poker_hand_id', $hand->id)->count()
        + ChipTransaction::where('from_poker_hand_id', $hand->id)->count();

    expect($ledgerAfter)->toBe($ledgerBefore);

    // Deactivated credentials no longer authenticate.
    expect($p1->refresh()->email_verified_at)->toBeNull();
});
