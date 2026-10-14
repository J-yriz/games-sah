<?php

use App\Actions\Chips\TransferChips;
use App\Models\ChipTransaction;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function fundWalletForPage(User $user, int $balance): void
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

test('guests cannot open the wallet page', function () {
    $this->get(route('wallet'))->assertRedirect(route('login'));
});

test('unverified users cannot open the wallet page', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('wallet'))->assertRedirect(route('verification.notice'));
});

test('wallet page shows the current balance', function () {
    $user = User::factory()->create();
    fundWalletForPage($user, 7_500);

    $this->actingAs($user)->get(route('wallet'))
        ->assertOk()
        ->assertSee('7,500');
});

test('full transfer flow succeeds through review and confirm', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWalletForPage($sender, 10_000);
    fundWalletForPage($recipient, 0);

    $this->actingAs($sender);

    Livewire::test('pages::wallet')
        ->set('recipientUsername', $recipient->username)
        ->set('amount', '2500')
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('confirming', true)
        ->call('send')
        ->assertHasNoErrors();

    expect($sender->wallet->refresh()->balance)->toBe(7_500)
        ->and($recipient->wallet->refresh()->balance)->toBe(2_500);
});

test('transfer with insufficient balance surfaces an error and moves nothing', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    fundWalletForPage($sender, 100);
    fundWalletForPage($recipient, 0);

    $this->actingAs($sender);

    Livewire::test('pages::wallet')
        ->set('recipientUsername', $recipient->username)
        ->set('amount', '5000')
        ->call('review')
        ->call('send')
        ->assertHasErrors(['amount']);

    expect($sender->wallet->refresh()->balance)->toBe(100)
        ->and($recipient->wallet->refresh()->balance)->toBe(0);
});

test('transfer to self is rejected', function () {
    $sender = User::factory()->create();
    fundWalletForPage($sender, 10_000);

    $this->actingAs($sender);

    Livewire::test('pages::wallet')
        ->set('recipientUsername', $sender->username)
        ->set('amount', '100')
        ->call('review')
        ->assertHasErrors(['recipientUsername']);
});

test('recipient search excludes the current user and hides emails', function () {
    $me = User::factory()->create(['username' => 'myself33']);
    $other = User::factory()->create(['username' => 'selfless_player', 'email' => 'hidden-secret@example.com']);

    $this->actingAs($me);

    $response = Livewire::test('pages::wallet')
        ->set('search', 'self');

    $usernames = $response->get('matches')->pluck('username');

    expect($usernames)->toContain('selfless_player')
        ->and($usernames)->not->toContain('myself33');

    $response->assertDontSee('hidden-secret@example.com');
});

test('users cannot read other users history', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $carol = User::factory()->create();
    fundWalletForPage($alice, 10_000);
    fundWalletForPage($bob, 10_000);
    fundWalletForPage($carol, 10_000);

    // Bob sends to Carol: Alice is not involved.
    $bob->wallet->refresh();
    app(TransferChips::class)->handle(
        $bob, $carol->username, 500, (string) Str::uuid()
    );

    $this->actingAs($alice);

    $response = Livewire::test('pages::wallet');
    $history = $response->get('history');

    // Alice only sees her own initial grant.
    expect($history)->toHaveCount(1)
        ->and($history->first()->type)->toBe(ChipTransaction::TYPE_INITIAL_GRANT);
});
