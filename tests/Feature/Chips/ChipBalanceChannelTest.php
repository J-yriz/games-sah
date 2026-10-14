<?php

use App\Broadcasting\WalletChannel;
use App\Models\ChipTransaction;
use App\Models\User;

function fundWalletForChannel(User $user, int $balance): void
{
    $wallet = $user->wallet()->create(['balance' => $balance]);

    ChipTransaction::create([
        'from_wallet_id' => null,
        'to_wallet_id' => $wallet->id,
        'type' => ChipTransaction::TYPE_INITIAL_GRANT,
        'amount' => $balance,
        'idempotency_key' => 'initial-grant:'.$user->id,
        'metadata' => ['user_id' => $user->id, 'grant' => 'test'],
    ]);
}

test('wallet owner can join their private wallet channel', function () {
    $user = User::factory()->create();
    fundWalletForChannel($user, 100);

    expect(app(WalletChannel::class)->join($user, $user->wallet->id))->toBeTrue();
});

test('other users cannot join someone elses wallet channel', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    fundWalletForChannel($owner, 100);
    fundWalletForChannel($intruder, 100);

    expect(app(WalletChannel::class)->join($intruder, $owner->wallet->id))->toBeFalse();
});

test('users without a wallet cannot join any wallet channel', function () {
    $owner = User::factory()->create();
    $walletless = User::factory()->create();
    fundWalletForChannel($owner, 100);

    expect(app(WalletChannel::class)->join($walletless, $owner->wallet->id))->toBeFalse();
});
