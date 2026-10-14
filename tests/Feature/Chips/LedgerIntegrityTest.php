<?php

use App\Actions\Chips\TransferChips;
use App\Models\ChipTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

function fundLedgerUser(User $user, int $balance): void
{
    $wallet = $user->wallet()->create(['balance' => $balance]);

    if ($balance > 0) {
        ChipTransaction::create([
            'from_wallet_id' => null,
            'to_wallet_id' => $wallet->id,
            'type' => ChipTransaction::TYPE_INITIAL_GRANT,
            'amount' => $balance,
            'idempotency_key' => 'initial-grant:'.$user->id,
            'metadata' => ['user_id' => $user->id, 'grant' => 'test'],
        ]);
    }
}

test('ledger rows survive recipient deletion with sender reference intact', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundLedgerUser($sender, 10_000);
    fundLedgerUser($recipient, 0);

    $tx = app(TransferChips::class)->handle($sender, $recipient->username, 1_000, (string) Str::uuid())->transaction;

    $recipient->delete();

    $row = ChipTransaction::find($tx->id);

    expect($row)->not->toBeNull()
        ->and($row->from_wallet_id)->toBe($sender->wallet->id)
        ->and($row->to_wallet_id)->toBeNull()
        ->and($row->amount)->toBe(1_000)
        ->and($row->metadata['sender_username'])->toBe($sender->username)
        ->and($row->metadata['recipient_username'])->toBe($recipient->username);
});

test('sender identity stays traceable through metadata after sender deletion', function () {
    $sender = User::factory()->create(['username' => 'ghostsender']);
    $recipient = User::factory()->create();
    fundLedgerUser($sender, 10_000);
    fundLedgerUser($recipient, 0);

    $tx = app(TransferChips::class)->handle($sender, $recipient->username, 500, (string) Str::uuid())->transaction;

    $senderUsername = $sender->username;
    $sender->delete();

    $row = ChipTransaction::find($tx->id);

    expect($row)->not->toBeNull()
        ->and($row->metadata['sender_username'])->toBe($senderUsername)
        ->and(Wallet::where('user_id', $sender->id)->count())->toBe(0);
});

test('backfill then transfer mints exactly one grant', function () {
    $legacy = User::factory()->create();
    $recipient = User::factory()->create();
    fundLedgerUser($recipient, 0);

    $this->artisan('chips:backfill-wallets')->assertSuccessful();

    app(TransferChips::class)->handle($legacy->refresh(), $recipient->username, 100, (string) Str::uuid());

    expect(ChipTransaction::where('idempotency_key', 'initial-grant:'.$legacy->id)->count())->toBe(1)
        ->and($legacy->wallet()->count())->toBe(1);
});

test('transfer (lazy grant) then backfill keeps a single wallet and grant', function () {
    $legacy = User::factory()->create();
    $recipient = User::factory()->create();
    fundLedgerUser($recipient, 0);

    app(TransferChips::class)->handle($legacy, $recipient->username, 100, (string) Str::uuid());

    $this->artisan('chips:backfill-wallets')->assertSuccessful();

    expect($legacy->wallet()->count())->toBe(1)
        ->and(ChipTransaction::where('idempotency_key', 'initial-grant:'.$legacy->id)->count())->toBe(1);
});

test('initial grant ledger always matches a funded wallet balance', function () {
    $user = User::factory()->create();
    fundLedgerUser($user, 10_000);

    $grants = (int) ChipTransaction::where('to_wallet_id', $user->wallet->id)
        ->where('type', ChipTransaction::TYPE_INITIAL_GRANT)
        ->sum('amount');

    expect($grants)->toBe($user->wallet->balance);
});
