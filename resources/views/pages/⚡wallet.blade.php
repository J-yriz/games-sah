<?php

use App\Actions\Chips\Exceptions\IdempotencyConflictException;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Chips\Exceptions\RecipientIneligibleException;
use App\Actions\Chips\Exceptions\RecipientNotFoundException;
use App\Actions\Chips\Exceptions\SelfTransferException;
use App\Actions\Chips\TransferChips;
use App\Actions\Chips\Exceptions\ChipTransferException;
use App\Models\ChipTransaction;
use App\Models\User;
use App\Models\Wallet;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wallet')] class extends Component
{
    public string $search = '';

    public string $recipientUsername = '';

    public string $amount = '';

    public string $note = '';

    public string $operationId = '';

    public bool $confirming = false;

    public string $statusMessage = '';

    public ?int $walletIdForChannel = null;

    public function mount(): void
    {
        $this->operationId = (string) Str::uuid();
        $this->walletIdForChannel = Auth::user()->wallet?->id;
    }

    #[Computed]
    public function balance(): int
    {
        // Fresh query on purpose: Auth::user()->wallet would return the
        // relation memoized on the user instance earlier in this request.
        return (int) (Wallet::where('user_id', Auth::id())->value('balance') ?? 0);
    }

    #[Computed]
    public function matches(): \Illuminate\Support\Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        return User::where('username', 'like', '%'.mb_strtolower(trim($this->search)).'%')
            ->where('id', '!=', Auth::id())
            ->orderBy('username')
            ->limit(5)
            ->get(['id', 'name', 'username']);
    }

    #[Computed]
    public function history(): \Illuminate\Support\Collection
    {
        $walletId = Auth::user()->wallet?->id;

        if ($walletId === null) {
            return collect();
        }

        return ChipTransaction::where('from_wallet_id', $walletId)
            ->orWhere('to_wallet_id', $walletId)
            ->with(['fromWallet.user', 'toWallet.user'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    public function selectRecipient(string $username): void
    {
        $this->recipientUsername = Str::lower($username);
        $this->search = '';
        $this->resetErrorBag('recipientUsername');
    }

    public function review(): void
    {
        $this->statusMessage = '';
        $this->validate($this->transferRules());

        $username = Str::lower(trim($this->recipientUsername));

        if ($username === Str::lower((string) Auth::user()->username)) {
            $this->addError('recipientUsername', __('You cannot transfer chips to yourself.'));

            return;
        }

        if (! User::where('username', $username)->exists()) {
            $this->addError('recipientUsername', __('Recipient not found.'));

            return;
        }

        $this->recipientUsername = $username;
        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    public function send(TransferChips $transfer): void
    {
        $this->validate($this->transferRules());

        $rateKey = 'chip-transfer:'.Auth::id();
        $maxAttempts = (int) config('chips.transfer_rate_per_minute', 10);

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $this->addError('amount', __('Too many transfer attempts. Please try again later.'));

            return;
        }

        RateLimiter::hit($rateKey);

        try {
            $result = $transfer->handle(
                Auth::user(),
                $this->recipientUsername,
                (int) $this->amount,
                $this->operationId,
                trim($this->note) !== '' ? trim($this->note) : null,
            );
        } catch (SelfTransferException|RecipientNotFoundException|RecipientIneligibleException $e) {
            $this->confirming = false;
            $this->addError('recipientUsername', $this->safeError($e));

            return;
        } catch (InsufficientBalanceException|InvalidTransferAmountException $e) {
            $this->confirming = false;
            $this->addError('amount', $this->safeError($e));

            return;
        } catch (IdempotencyConflictException $e) {
            $this->confirming = false;
            $this->addError('amount', $this->safeError($e));

            return;
        }

        $this->statusMessage = $result->replayed
            ? __('Transfer already processed. Showing the original transaction.')
            : __('Transfer successful.');

        $this->reset(['search', 'recipientUsername', 'amount', 'note', 'confirming']);
        $this->operationId = (string) Str::uuid();

        unset($this->history, $this->balance, $this->matches);
        Flux::toast(variant: 'success', text: $this->statusMessage);
    }

    /**
     * Re-read authoritative state from the database. Used by polling
     * and after reconnect: broadcast events are notifications only.
     */
    public function refreshState(): void
    {
        unset($this->history, $this->balance);
    }

    /**
     * User-facing error text. Chip domain exceptions carry curated
     * messages; anything else is logged and replaced so responses never
     * leak internals.
     */
    private function safeError(\Throwable $e): string
    {
        if ($e instanceof ChipTransferException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }

        Log::warning('Wallet operation failed unexpectedly.', [
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        return __('Something went wrong. Please try again.');
    }

    public function counterpart(ChipTransaction $transaction): string
    {
        $walletId = Auth::user()->wallet?->id;

        if ($transaction->from_wallet_id === $walletId) {
            return $transaction->metadata['recipient_username']
                ?? $transaction->toWallet?->user?->username
                ?? __('unknown');
        }

        // Initial grants have no sender wallet.
        if ($transaction->from_wallet_id === null) {
            return __('system');
        }

        return $transaction->metadata['sender_username']
            ?? $transaction->fromWallet?->user?->username
            ?? __('unknown');
    }

    public function direction(ChipTransaction $transaction): string
    {
        return $transaction->from_wallet_id === Auth::user()->wallet?->id ? 'out' : 'in';
    }

    /**
     * @return array<string, mixed>
     */
    private function transferRules(): array
    {
        return [
            'recipientUsername' => ['required', 'string', 'min:3', 'max:30'],
            'amount' => ['required', 'integer', 'min:'.config('chips.min_transfer', 1), 'max:'.config('chips.max_transfer', 1_000_000_000)],
            'note' => ['nullable', 'string', 'max:140'],
            'operationId' => ['required', 'string', 'max:64'],
        ];
    }
}; ?>

<section class="w-full" wire:poll.10s="refreshState">
    <flux:heading size="xl">{{ __('Wallet') }}</flux:heading>
    <flux:subheading>{{ __('Send virtual chips to other players. Chips hold no monetary value.') }}</flux:subheading>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg">{{ __('Balance') }}</flux:heading>
                <p class="mt-2 text-4xl font-semibold tabular-nums" data-test="wallet-balance">
                    {{ number_format($this->balance) }}
                </p>
                <flux:text>{{ __('chips') }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Transfer chips') }}</flux:heading>

                @if ($statusMessage !== '')
                    <flux:callout variant="success" class="mt-4" data-test="transfer-status">
                        {{ $statusMessage }}
                    </flux:callout>
                @endif

                @if (! $confirming)
                    <form wire:submit="review" class="mt-4 space-y-4">
                        <div>
                            <flux:input
                                wire:model.live="search"
                                :label="__('Find recipient')"
                                type="text"
                                :placeholder="__('Type at least 2 characters of a username')"
                                autocomplete="off"
                            />
                            @if ($this->matches->isNotEmpty())
                                <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                    @foreach ($this->matches as $match)
                                        <li wire:key="match-{{ $match->id }}">
                                            <button
                                                type="button"
                                                wire:click="selectRecipient('{{ $match->username }}')"
                                                class="flex w-full items-center justify-between px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            >
                                                <span class="font-medium">{{ $match->username }}</span>
                                                <span class="text-sm text-zinc-500">{{ $match->name }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <flux:input wire:model="recipientUsername" :label="__('Recipient username')" type="text" required autocomplete="off" />

                        <flux:input wire:model="amount" :label="__('Amount (chips)')" type="number" min="1" required />

                        <flux:textarea wire:model="note" :label="__('Note (optional)')" rows="2" maxlength="140" />

                        <flux:button variant="primary" type="submit" class="w-full" data-test="review-transfer-button">
                            {{ __('Review transfer') }}
                        </flux:button>
                    </form>
                @else
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Recipient') }}</dt>
                            <dd class="font-medium">{{ $recipientUsername }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Amount') }}</dt>
                            <dd class="font-medium tabular-nums">{{ number_format((int) $amount) }} {{ __('chips') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Balance before') }}</dt>
                            <dd class="tabular-nums">{{ number_format($this->balance) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Balance after') }}</dt>
                            <dd class="font-medium tabular-nums">{{ number_format($this->balance - (int) $amount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Fee') }}</dt>
                            <dd>0 {{ __('chips') }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex gap-3">
                        <flux:button variant="ghost" wire:click="cancel" class="flex-1">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button variant="primary" wire:click="send" class="flex-1" data-test="confirm-transfer-button">
                            {{ __('Confirm and send') }}
                        </flux:button>
                    </div>
                @endif
            </flux:card>
        </div>

        <div class="lg:col-span-2">
            <flux:card>
                <flux:heading size="lg">{{ __('Recent activity') }}</flux:heading>

                @if ($this->history->isEmpty())
                    <flux:text class="mt-4">{{ __('No chip movements yet.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->history as $transaction)
                            <li wire:key="tx-{{ $transaction->id }}" class="flex items-center justify-between py-3">
                                <div>
                                    <p class="font-medium">
                                        @if ($transaction->type === 'initial_grant')
                                            {{ __('Initial grant') }}
                                        @elseif ($this->direction($transaction) === 'out')
                                            {{ __('Sent to :name', ['name' => $this->counterpart($transaction)]) }}
                                        @else
                                            {{ __('Received from :name', ['name' => $this->counterpart($transaction)]) }}
                                        @endif
                                    </p>
                                    <p class="text-sm text-zinc-500">
                                        {{ $transaction->created_at?->format('d M Y H:i') }} · #{{ $transaction->id }}
                                    </p>
                                </div>
                                <p class="font-semibold tabular-nums {{ $this->direction($transaction) === 'out' ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                    {{ $this->direction($transaction) === 'out' ? '−' : '+' }}{{ number_format($transaction->amount) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>
        </div>
    </div>
</section>

@script
<script>
    // Push path: refresh authoritative state from the database as soon as
    // the wallet channel notifies us. The event payload never touches
    // balances directly. Polling above remains the fallback.
    const walletId = $wire.get('walletIdForChannel');

    if (walletId && window.Echo) {
        window.Echo.private(`wallets.${walletId}`).listen('.balance.updated', () => {
            $wire.refreshState();
        });
    }
</script>
@endscript
