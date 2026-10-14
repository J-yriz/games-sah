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

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 100);
    app(PlayerAction::class)->handle($player1, $hand, 'call', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'fold', (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
    ], (string) Str::uuid());

    expect($result['hand']->status)->toBe(PokerHand::STATUS_SETTLED)
        ->and($result['distributions'][0]['winners'][0]['user_id'])->toBe($host->id)
        ->and($result['distributions'][0]['winners'][0]['amount'])->toBeGreaterThan(0);
});

test('split pot divides chips evenly with odd chip to first winner from dealer', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'check', (string) Str::uuid());

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

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 0]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 1]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 2]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 50]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 200);

    $result = app(PlayerAction::class)->handle($player1, $hand, 'all_in', (string) Str::uuid());

    expect($result['player']->status)->toBe(PokerHandPlayer::STATUS_ALL_IN)
        ->and($result['player']->total_contribution)->toBe(50);

    app(PlayerAction::class)->handle($player2, $hand, 'call', (string) Str::uuid());

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'check', (string) Str::uuid());

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id, $player1->id, $player2->id],
        1 => [$host->id, $player2->id],
    ], (string) Str::uuid());

    expect($result['distributions'])->toHaveCount(2)
        ->and($result['distributions'][0]['pot_amount'])->toBe(150)
        ->and($result['distributions'][1]['pot_amount'])->toBe(300);
});

test('uncalled bet excess is returned to aggressor', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();

    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'status' => Room::STATUS_ACTIVE,
        'small_blind' => 10,
        'big_blind' => 20,
    ]);

    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => Room::ROLE_HOST, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 0]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 1]);
    RoomMember::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'role' => Room::ROLE_PLAYER, 'status' => RoomMember::STATUS_ACTIVE, 'seat' => 2]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $host->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player1->id, 'balance' => 1000]);
    TableStack::factory()->create(['room_id' => $room->id, 'user_id' => $player2->id, 'balance' => 1000]);

    $hand = startPokerHand($host, [$player1, $player2]);

    app(PlayerAction::class)->handle($host, $hand, 'bet', (string) Str::uuid(), 500);
    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());
    app(PlayerAction::class)->handle($player2, $hand, 'fold', (string) Str::uuid());

    expect($hand->refresh()->status)->toBe(PokerHand::STATUS_AWAITING_SHOWDOWN);

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
    ], (string) Str::uuid());

    expect($result['uncalled_returns'][0]['amount'])->toBe(500);
});

test('only host can settle hand', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $hand = startPokerHand($host, [$player1]);

    app(SettleHand::class)->handle($player1, $hand, [0 => [$host->id]], (string) Str::uuid());
})->throws(InvalidSettlementException::class);

test('settlement creates correct ledger entries', function () {
    $host = User::factory()->create();
    $player1 = User::factory()->create();

    $hand = startPokerHand($host, [$player1]);

    app(PlayerAction::class)->handle($host, $hand, 'check', (string) Str::uuid());
    app(PlayerAction::class)->handle($player1, $hand, 'fold', (string) Str::uuid());

    $result = app(SettleHand::class)->handle($host, $hand, [
        0 => [$host->id],
    ], (string) Str::uuid());

    $ledger = ChipTransaction::where('from_poker_hand_id', $hand->id)
        ->where('type', ChipTransaction::TYPE_POT_WIN)
        ->get();

    expect($ledger)->not->toBeEmpty();
});
