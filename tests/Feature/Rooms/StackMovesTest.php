<?php

use App\Actions\Chips\BuyInToRoom;
use App\Actions\Chips\CashOutFromRoom;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\RoomIdempotencyConflictException;
use App\Actions\Rooms\JoinRoom;
use App\Events\ChipBalanceUpdated;
use App\Events\RoomStateUpdated;
use App\Models\ChipTransaction;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function seatPlayer(User $host, User $player, int $walletBalance = 10_000, array $roomOverrides = []): Room
{
    $room = app(CreateRoom::class)->handle($host, array_merge([
        'name' => 'Friday Poker',
        'max_players' => 8,
        'min_buy_in' => 100,
        'max_buy_in' => 5_000,
    ], $roomOverrides));

    app(JoinRoom::class)->handle($player, $room->invite_code);

    $wallet = $player->wallet()->create(['balance' => $walletBalance]);

    if ($walletBalance > 0) {
        ChipTransaction::create([
            'from_wallet_id' => null,
            'to_wallet_id' => $wallet->id,
            'type' => ChipTransaction::TYPE_INITIAL_GRANT,
            'amount' => $walletBalance,
            'idempotency_key' => 'initial-grant:'.$player->id,
            'metadata' => ['user_id' => $player->id, 'grant' => 'test'],
        ]);
    }

    return $room->refresh();
}

function buyInService(): BuyInToRoom
{
    return app(BuyInToRoom::class);
}

function cashOutService(): CashOutFromRoom
{
    return app(CashOutFromRoom::class);
}

test('buy-in moves chips from wallet to table stack', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    $result = buyInService()->handle($player, $room, 1_000, (string) Str::uuid());

    expect($result->replayed)->toBeFalse()
        ->and($result->walletBalance)->toBe(9_000)
        ->and($result->stackBalance)->toBe(1_000);

    $this->assertDatabaseHas('chip_transactions', [
        'from_wallet_id' => $player->wallet->id,
        'type' => ChipTransaction::TYPE_BUY_IN,
        'amount' => 1_000,
    ]);
});

test('rebuy tops up an existing stack', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 1_000, (string) Str::uuid());
    $result = buyInService()->handle($player, $room, 500, (string) Str::uuid(), ChipTransaction::TYPE_REBUY);

    expect($result->walletBalance)->toBe(8_500)
        ->and($result->stackBalance)->toBe(1_500)
        ->and($result->transaction->type)->toBe(ChipTransaction::TYPE_REBUY);
});

test('cash-out returns chips to the wallet', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 1_000, (string) Str::uuid());
    $result = cashOutService()->handle($player, $room, 400, (string) Str::uuid());

    expect($result->walletBalance)->toBe(9_400)
        ->and($result->stackBalance)->toBe(600)
        ->and($result->transaction->type)->toBe(ChipTransaction::TYPE_CASH_OUT);
});

test('buy-in conserves the combined total', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 2_500, (string) Str::uuid());

    $wallet = $player->wallet->refresh()->balance;
    $stack = $room->stacks()->where('user_id', $player->id)->first()->balance;

    expect($wallet + $stack)->toBe(10_000);
});

test('buy-in below room minimum is rejected', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 50, (string) Str::uuid());
})->throws(InvalidTransferAmountException::class);

test('buy-in above room maximum is rejected', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 6_000, (string) Str::uuid());
})->throws(InvalidTransferAmountException::class);

test('buy-in with insufficient wallet rolls back fully', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player, 200);

    try {
        buyInService()->handle($player, $room, 1_000, (string) Str::uuid());
    } catch (InsufficientBalanceException) {
    }

    expect($player->wallet->refresh()->balance)->toBe(200)
        ->and($room->stacks()->where('user_id', $player->id)->first()->balance)->toBe(0)
        ->and(ChipTransaction::whereIn('type', [ChipTransaction::TYPE_BUY_IN, ChipTransaction::TYPE_REBUY])->count())->toBe(0);
});

test('cash-out above the stack fails without side effects', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 1_000, (string) Str::uuid());

    try {
        cashOutService()->handle($player, $room, 1_001, (string) Str::uuid());
    } catch (InsufficientBalanceException) {
    }

    expect($player->wallet->refresh()->balance)->toBe(9_000)
        ->and($room->stacks()->where('user_id', $player->id)->first()->balance)->toBe(1_000);
});

test('non-members cannot buy in', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $outsider = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($outsider, $room, 500, (string) Str::uuid());
})->throws(NotMemberException::class);

test('duplicate buy-in replays without moving chips', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);
    $key = (string) Str::uuid();

    $first = buyInService()->handle($player, $room, 1_000, $key);
    $second = buyInService()->handle($player, $room, 1_000, $key);

    expect($second->replayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and($player->wallet->refresh()->balance)->toBe(9_000);
});

test('same operation id with a different amount is a conflict', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);
    $key = (string) Str::uuid();

    buyInService()->handle($player, $room, 1_000, $key);
    buyInService()->handle($player, $room, 2_000, $key);
})->throws(RoomIdempotencyConflictException::class);

test('duplicate cash-out replays without moving chips', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);
    buyInService()->handle($player, $room, 1_000, (string) Str::uuid());
    $key = (string) Str::uuid();

    $first = cashOutService()->handle($player, $room, 400, $key);
    $second = cashOutService()->handle($player, $room, 400, $key);

    expect($second->replayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and($player->wallet->refresh()->balance)->toBe(9_400);
});

test('stack moves notify the wallet owner and the room', function () {
    Event::fake([ChipBalanceUpdated::class, RoomStateUpdated::class]);

    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player);

    buyInService()->handle($player, $room, 1_000, (string) Str::uuid());

    Event::assertDispatched(ChipBalanceUpdated::class);
    Event::assertDispatched(RoomStateUpdated::class, fn ($e) => $e->roomId === $room->id);
});

test('failed stack moves dispatch nothing', function () {
    $host = User::factory()->create();
    $player = User::factory()->create();
    $room = seatPlayer($host, $player, 100);

    Event::fake([ChipBalanceUpdated::class, RoomStateUpdated::class]);

    try {
        buyInService()->handle($player, $room, 1_000, (string) Str::uuid());
    } catch (InsufficientBalanceException) {
    }

    Event::assertNotDispatched(ChipBalanceUpdated::class);
    Event::assertNotDispatched(RoomStateUpdated::class);
});
