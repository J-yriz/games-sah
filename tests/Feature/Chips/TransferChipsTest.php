<?php

use App\Actions\Chips\Exceptions\IdempotencyConflictException;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Chips\Exceptions\RecipientIneligibleException;
use App\Actions\Chips\Exceptions\RecipientNotFoundException;
use App\Actions\Chips\Exceptions\SelfTransferException;
use App\Actions\Chips\TransferChips;
use App\Events\ChipBalanceUpdated;
use App\Models\ChipTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function fundWallet(User $user, int $balance): void
{
    $wallet = $user->wallet()->create(['balance' => $balance]);

    // Zero-balance wallets carry no grant row: the ledger only records
    // positive movements (enforced by CHECK on PostgreSQL/MySQL).
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

function transferService(): TransferChips
{
    return app(TransferChips::class);
}

test('registration creates a wallet with the initial grant exactly once', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'username' => 'janedoe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('username', 'janedoe')->firstOrFail();

    expect($user->wallet)->not->toBeNull()
        ->and($user->wallet->balance)->toBe((int) config('chips.initial_grant'))
        ->and(ChipTransaction::where('to_wallet_id', $user->wallet->id)->count())->toBe(1)
        ->and(ChipTransaction::where('idempotency_key', 'initial-grant:'.$user->id)->count())->toBe(1);
});

test('successful transfer moves chips atomically and conserves the total', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 10_000);

    $result = transferService()->handle($sender, $recipient->username, 3_000, (string) Str::uuid());

    expect($result->replayed)->toBeFalse()
        ->and($result->senderBalance)->toBe(7_000)
        ->and($result->recipientBalance)->toBe(13_000)
        ->and($sender->wallet->refresh()->balance + $recipient->wallet->refresh()->balance)->toBe(20_000);

    $this->assertDatabaseHas('chip_transactions', [
        'from_wallet_id' => $sender->wallet->id,
        'to_wallet_id' => $recipient->wallet->id,
        'type' => ChipTransaction::TYPE_TRANSFER,
        'amount' => 3_000,
    ]);
});

test('transfer is rejected on insufficient balance without side effects', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 500);
    fundWallet($recipient, 10_000);

    transferService()->handle($sender, $recipient->username, 501, (string) Str::uuid());
})->throws(InsufficientBalanceException::class);

test('failed transfer leaves no partial balance or ledger changes', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 500);
    fundWallet($recipient, 1_000);

    try {
        transferService()->handle($sender, $recipient->username, 5_000, (string) Str::uuid());
    } catch (InsufficientBalanceException) {
    }

    expect($sender->wallet->refresh()->balance)->toBe(500)
        ->and($recipient->wallet->refresh()->balance)->toBe(1_000)
        ->and(ChipTransaction::where('type', ChipTransaction::TYPE_TRANSFER)->count())->toBe(0);
});

test('zero, negative, and oversized amounts are rejected', function (int $amount) {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 0);

    transferService()->handle($sender, $recipient->username, $amount, (string) Str::uuid());
})->with([0, -1, 1_000_000_001])->throws(InvalidTransferAmountException::class);

test('transfer to self is rejected', function () {
    $sender = User::factory()->create();
    fundWallet($sender, 10_000);

    transferService()->handle($sender, $sender->username, 100, (string) Str::uuid());
})->throws(SelfTransferException::class);

test('transfer to an unknown username is rejected', function () {
    $sender = User::factory()->create();
    fundWallet($sender, 10_000);

    transferService()->handle($sender, 'ghost-user-xyz', 100, (string) Str::uuid());
})->throws(RecipientNotFoundException::class);

test('transfer to an unverified account is rejected', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->unverified()->create();
    fundWallet($sender, 10_000);

    transferService()->handle($sender, $recipient->username, 100, (string) Str::uuid());
})->throws(RecipientIneligibleException::class);

test('recipient lookup is case-insensitive', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 0);

    $result = transferService()->handle($sender, strtoupper($recipient->username), 100, (string) Str::uuid());

    expect($result->senderBalance)->toBe(9_900)
        ->and($result->recipientBalance)->toBe(100);
});

test('duplicate request with the same operation id replays without moving chips', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 0);

    $key = (string) Str::uuid();

    $first = transferService()->handle($sender, $recipient->username, 1_000, $key);
    $second = transferService()->handle($sender, $recipient->username, 1_000, $key);

    expect($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and($sender->wallet->refresh()->balance)->toBe(9_000)
        ->and($recipient->wallet->refresh()->balance)->toBe(1_000)
        ->and(ChipTransaction::where('idempotency_key', $key)->count())->toBe(1);
});

test('same operation id with different parameters is rejected as conflict', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 0);

    $key = (string) Str::uuid();

    transferService()->handle($sender, $recipient->username, 1_000, $key);

    transferService()->handle($sender, $recipient->username, 2_000, $key);
})->throws(IdempotencyConflictException::class);

test('no event is dispatched when the transfer fails', function () {
    Event::fake([ChipBalanceUpdated::class]);

    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 100);
    fundWallet($recipient, 0);

    try {
        transferService()->handle($sender, $recipient->username, 5_000, (string) Str::uuid());
    } catch (InsufficientBalanceException) {
    }

    Event::assertNotDispatched(ChipBalanceUpdated::class);
});

test('both wallets are notified after a successful commit', function () {
    Event::fake([ChipBalanceUpdated::class]);

    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($recipient, 0);

    transferService()->handle($sender, $recipient->username, 1_000, (string) Str::uuid());

    Event::assertDispatchedTimes(ChipBalanceUpdated::class, 2);
});

test('backfill command is idempotent for legacy accounts', function () {
    $legacy = User::factory()->create();

    $this->artisan('chips:backfill-wallets')->assertSuccessful();
    $this->artisan('chips:backfill-wallets')->assertSuccessful();

    expect($legacy->wallet()->count())->toBe(1)
        ->and($legacy->wallet->balance)->toBe((int) config('chips.initial_grant'))
        ->and(ChipTransaction::where('idempotency_key', 'initial-grant:'.$legacy->id)->count())->toBe(1);
});

test('two competing full-balance transfers never overdraw the sender', function () {
    $sender = User::factory()->create();
    $firstRecipient = User::factory()->create();
    $secondRecipient = User::factory()->create();
    fundWallet($sender, 10_000);
    fundWallet($firstRecipient, 0);
    fundWallet($secondRecipient, 0);

    $senderId = $sender->id;
    $firstUsername = $firstRecipient->username;
    $secondUsername = $secondRecipient->username;

    // NOTE: the default sync concurrency driver runs these sequentially.
    // This proves balance re-validation inside the locked transaction;
    // true row-lock contention must be verified on the production
    // database (PostgreSQL/MySQL), where lockForUpdate is enforced.
    $outcomes = Concurrency::driver('sync')->run([
        function () use ($senderId, $firstUsername): string {
            try {
                app(TransferChips::class)->handle(
                    User::find($senderId), $firstUsername, 10_000, (string) Str::uuid()
                );

                return 'ok';
            } catch (InsufficientBalanceException) {
                return 'insufficient';
            }
        },
        function () use ($senderId, $secondUsername): string {
            try {
                app(TransferChips::class)->handle(
                    User::find($senderId), $secondUsername, 10_000, (string) Str::uuid()
                );

                return 'ok';
            } catch (InsufficientBalanceException) {
                return 'insufficient';
            }
        },
    ]);

    expect($outcomes)->toContain('ok')
        ->and($outcomes)->toContain('insufficient')
        ->and($sender->wallet->refresh()->balance)->toBe(0)
        ->and(
            $firstRecipient->wallet->refresh()->balance
            + $secondRecipient->wallet->refresh()->balance
        )->toBe(10_000);
});
