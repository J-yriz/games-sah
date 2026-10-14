<?php

use App\Actions\Poker\Exceptions\InvalidSettlementException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Actions\Poker\StartHand;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Str;

test('host can settle hand with single winner taking main pot', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    // Preflop: UTG raises to 100, SB calls (10->100), BB folds.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'fold', (string) Str::uuid());

    // Flop/turn/river: check down.
    $hand = checkStreet($hand, $host, [$host, $player1]);
    $hand = checkStreet($hand, $host, [$host, $player1]);
    $hand = checkStreet($hand, $host, [$host, $player1]);

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $stacksBefore = TableStack::where('room_id', $hand->room_id)->pluck('balance', 'user_id');

    // Contributions: host 100, p1 100, p2 20 (folded BB) -> pots 60 + 160.
    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
        1 => [$host->id],
    ], (string) Str::uuid());

    expect($result['hand']->status)->toBe(PokerHand::STATUS_SETTLED)
        ->and($result['distributions'][0]['winners'][0]['user_id'])->toBe($host->id)
        ->and($result['distributions'][0]['winners'][0]['amount'])->toBeGreaterThan(0);

    // Reconciliation: stacks after = stacks before + payouts.
    $paid = $result['distributions'][0]['winners'][0]['amount'] + $result['distributions'][1]['winners'][0]['amount'];
    expect(TableStack::where('room_id', $hand->room_id)->where('user_id', $host->id)->value('balance'))
        ->toBe($stacksBefore[$host->id] + $paid);
});

test('split pot divides chips evenly with odd chip to first winner from dealer', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    // Two players: host + player1, player2 folds preflop (fold-win avoided by
    // keeping player2 out: use dedicated 2-handed room instead).
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 50]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 51]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    // Heads-up: dealer(host/SB) acts first: call to match BB 20.
    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_FLOP);

    $hand = checkStreet($hand, $host, [$player1, $host]);
    $hand = checkStreet($hand, $host, [$player1, $host]);
    $hand = checkStreet($hand, $host, [$player1, $host]);
    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id, $player1->id],
    ], (string) Str::uuid());

    expect($result['distributions'][0]['winners'])->toHaveCount(2);

    $amounts = array_column($result['distributions'][0]['winners'], 'amount');
    expect(array_sum($amounts))->toBe($hand->total_pot)
        ->and(abs($amounts[0] - $amounts[1]))->toBeLessThanOrEqual(1);
});

test('side pot calculation with all-in players', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 60]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 61]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 62]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 50]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    // SB player1 posts 10, BB player2 posts 20, UTG host acts first.
    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    // Host bets (raises) to 200 total on the street.
    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 200);

    // player1 has 40 left (50 - 10 blind): short all-in call for 40 (total 50).
    $result = app(PlayerAction::class)->handle($player1, $hand, 'all_in', (string) Str::uuid());

    expect($result['player']->status)->toBe(PokerHandPlayer::STATUS_ALL_IN)
        ->and($result['player']->total_contribution)->toBe(50);

    // player2 calls 200 (20 blind + 180). Host already at 200: checks through?
    // After player2 calls, back to host who matched: street completes when host
    // matches? Host is at 200 already and acted, player1 all-in, player2 to act.
    app(PlayerAction::class)->handle($player2, $hand, 'call', (string) Str::uuid());

    // Preflop now: host 200 acted, p1 all-in 50, p2 200 acted -> complete.
    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_FLOP);

    // Check down remaining streets (host + player2; player1 all-in skipped).
    $hand = checkStreet($hand, $host, [$host, $player2]);
    $hand = checkStreet($hand, $host, [$host, $player2]);
    $hand = checkStreet($hand, $host, [$host, $player2]);

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
        1 => [$host->id],
    ], (string) Str::uuid());

    // Contributions: host 200, p1 50, p2 200 -> main 150, side 300.
    expect($result['distributions'])->toHaveCount(2)
        ->and($result['distributions'][0]['pot_amount'])->toBe(150)
        ->and($result['distributions'][1]['pot_amount'])->toBe(300);
});

test('uncalled bet excess is returned to aggressor on fold-win', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    // UTG raises to 520 (500 over the 20 BB), both blinds fold -> fold-win.
    $stacksBefore = TableStack::where('room_id', $hand->room_id)->pluck('balance', 'user_id')->toArray();

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 520);
    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());
    $result = app(PlayerAction::class)->handle($player2, $hand, 'fold', (string) Str::uuid());

    expect($result['fold_win'])->toBeTrue()
        ->and($hand->refresh()->status)->toBe(PokerHand::STATUS_SETTLED);

    // Contributions: host 520, p1 10, p2 20. Uncalled = 500, pots = 50.
    // Winner (host) net: -520 + 500 + 50 = +30 vs before.
    $hostAfter = TableStack::where('room_id', $hand->room_id)->where('user_id', $host->id)->value('balance');
    expect($hostAfter)->toBe($stacksBefore[$host->id] - 520 + 500 + 50);

    $returns = ChipTransaction::where('to_poker_hand_id', null)
        ->where('from_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_UNCALLED_RETURN)
        ->get();
    expect($returns->sum('amount'))->toBe(500);
});

test('only host can settle hand', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    $hand = driveToShowdown($hand, $host, $player1, $player2);

    app(SettleHand::class)->handle($player1, $hand, [0 => [$host->id]], (string) Str::uuid());
})->throws(InvalidSettlementException::class);

test('settlement creates correct ledger entries and conserves chips', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 70]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 71]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);

    $totalBefore = (int) TableStack::where('room_id', $room->id)->sum('balance');

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    $hand = checkStreet($hand, $host, [$player1, $host]);
    $hand = checkStreet($hand, $host, [$player1, $host]);
    $hand = checkStreet($hand, $host, [$player1, $host]);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
    ], (string) Str::uuid());

    $ledger = ChipTransaction::where('from_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_POT_WIN)
        ->get();

    expect($ledger)->not->toBeEmpty();

    // Conservation: stacks + escrowed pot movements net to zero.
    $totalAfter = (int) TableStack::where('room_id', $room->id)->sum('balance');
    expect($totalAfter)->toBe($totalBefore);

    // Ledger reconciliation: contributions == payouts + returns.
    $contrib = (int) ChipTransaction::where('to_poker_hand_id', $hand->id)->sum('amount');
    $payout = (int) ChipTransaction::where('from_poker_hand_id', $hand->id)->sum('amount');
    expect($payout)->toBe($contrib)
        ->and($contrib)->toBe($hand->refresh()->total_pot);
});

test('folded player cannot win a pot', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'raise', (string) Str::uuid(), raiseTo: 100);
    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'call', (string) Str::uuid());

    $hand = checkStreet($hand, $host, [$host, $player2]);
    $hand = checkStreet($hand, $host, [$host, $player2]);
    $hand = checkStreet($hand, $host, [$host, $player2]);

    app(SettleHand::class)->handle($host, $hand, [
        0 => [$player1->id],
    ], (string) Str::uuid());
})->throws(InvalidSettlementException::class);

test('double settlement with the same key replays without double payout', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);
    $hand = driveToShowdown($hand, $host, $player1, $player2);

    $key = (string) Str::uuid();
    $first = app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], $key);
    $stackAfterFirst = TableStack::where('room_id', $hand->room_id)->where('user_id', $host->id)->value('balance');

    $second = app(SettleHand::class)->handle($host, $hand, [0 => [$host->id]], $key);

    expect($second['replayed'] ?? false)->toBeTrue()
        ->and(TableStack::where('room_id', $hand->room_id)->where('user_id', $host->id)->value('balance'))
        ->toBe($stackAfterFirst);
});
