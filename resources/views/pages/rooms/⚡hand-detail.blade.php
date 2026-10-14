<?php

use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Support\PotCalculator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Hand detail')] class extends Component
{
    public Room $room;

    public PokerHand $hand;

    public function mount(Room $room, PokerHand $hand): void
    {
        Gate::authorize('viewHistory', $room);
        abort_unless($hand->room_id === $room->id, 404);

        $this->room = $room;
        $this->hand = $hand;
    }

    #[Computed]
    public function players(): \Illuminate\Support\Collection
    {
        return PokerHandPlayer::where('hand_id', $this->hand->id)
            ->with('user:id,name,username')
            ->orderBy('seat')
            ->get();
    }

    #[Computed]
    public function actions(): \Illuminate\Support\Collection
    {
        return \App\Models\PokerAction::where('hand_id', $this->hand->id)
            ->with('user:id,username')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function ledger(): \Illuminate\Support\Collection
    {
        return ChipTransaction::where('to_poker_hand_id', $this->hand->id)
            ->orWhere('from_poker_hand_id', $this->hand->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{pots: array<int, array{amount: int, eligible: array<int, int>}>, uncalled: array{user_id: int, amount: int}|null}
     */
    #[Computed]
    public function pots(): array
    {
        return PotCalculator::calculate(
            $this->players->map(fn (PokerHandPlayer $p): array => [
                'user_id' => $p->user_id,
                'seat' => $p->seat,
                'total' => (int) $p->total_contribution,
                'folded' => $p->status === PokerHandPlayer::STATUS_FOLDED,
            ])->toArray(),
            (int) $this->hand->dealer_seat
        );
    }

    #[Computed]
    public function payoutsByPot(): \Illuminate\Support\Collection
    {
        return $this->ledger
            ->where('type', ChipTransaction::TYPE_POT_WIN)
            ->groupBy(fn (ChipTransaction $tx) => $tx->metadata['pot_layer'] ?? 0);
    }

    #[Computed]
    public function usernameFor(): \Closure
    {
        $byId = $this->players->keyBy('id');

        return function (?int $handPlayerId) use ($byId): string {
            if ($handPlayerId === null) {
                return '—';
            }

            return $byId->get($handPlayerId)?->user?->username ?? '#'.$handPlayerId;
        };
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Hand') }} #{{ $hand->hand_number }}</flux:heading>
            <flux:subheading>
                {{ $room->name }} · {{ __($hand->status) }} · {{ __($hand->street) }} ·
                {{ __('Pot') }} {{ number_format($hand->total_pot) }}
            </flux:subheading>
        </div>
        <flux:button :href="route('rooms.hands', $room)" wire:navigate variant="ghost">{{ __('History') }}</flux:button>
    </div>

    @if ($hand->status === 'cancelled')
        <flux:callout variant="danger" class="mt-4" data-test="cancel-banner">
            {{ __('Hand cancelled') }}@if ($hand->cancel_reason): {{ $hand->cancel_reason }}@endif
        </flux:callout>
    @endif
    <flux:text class="mt-2">{{ __('Physical cards are not recorded. Winners reflect host confirmation, never automatic evaluation.') }}</flux:text>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="lg">{{ __('Seats & contributions') }}</flux:heading>
            <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->players as $hp)
                    <li wire:key="hp-{{ $hp->id }}" class="flex items-center justify-between py-2">
                        <div>
                            <span class="font-medium">{{ $hp->user->username }}</span>
                            @if ($hp->is_dealer)<span class="ml-1 rounded bg-amber-200 px-1 text-xs font-bold">D</span>@endif
                            @if ($hp->is_small_blind)<span class="ml-1 text-xs text-zinc-500">SB</span>@endif
                            @if ($hp->is_big_blind)<span class="ml-1 text-xs text-zinc-500">BB</span>@endif
                            <p class="text-sm text-zinc-500">{{ __('Seat') }} {{ $hp->seat }} · {{ __($hp->status) }}</p>
                        </div>
                        <span class="font-semibold tabular-nums" data-test="contrib-{{ $hp->user_id }}">{{ number_format($hp->total_contribution) }}</span>
                    </li>
                @endforeach
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Action sequence') }}</flux:heading>
            <ul class="mt-4 space-y-1 text-sm">
                @forelse ($this->actions as $action)
                    <li wire:key="pa-{{ $action->id }}">
                        <span class="text-zinc-500">#{{ $action->id }} · {{ $action->street }}</span>
                        <span class="font-medium">{{ $action->user->username ?? '—' }}</span>
                        {{ __($action->action) }}
                        @if ($action->amount > 0)<span class="tabular-nums">{{ number_format($action->amount) }}</span>@endif
                        @if (($action->metadata['timeout'] ?? false))<span class="text-amber-600">({{ __('auto timeout') }})</span>@endif
                    </li>
                @empty
                    <flux:text>{{ __('No actions recorded.') }}</flux:text>
                @endforelse
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Pots & payouts') }}</flux:heading>
            @foreach ($this->pots['pots'] as $potIndex => $pot)
                <div class="mt-3 rounded border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="text-sm font-semibold">{{ __('Pot') }} {{ $potIndex + 1 }}: {{ number_format($pot['amount']) }}</p>
                    @foreach (($this->payoutsByPot->get($potIndex) ?? collect()) as $tx)
                        <p class="text-sm">{{ ($this->usernameFor)($tx->to_hand_player_id) }} · +{{ number_format($tx->amount) }}</p>
                    @endforeach
                </div>
            @endforeach
            @if ($this->pots['uncalled'] !== null)
                <flux:text class="mt-2">{{ __('Uncalled excess returned') }}: {{ number_format($this->pots['uncalled']['amount']) }}</flux:text>
            @endif
            @php $refunds = $this->ledger->where('type', \App\Models\ChipTransaction::TYPE_REFUND); @endphp
            @if ($refunds->isNotEmpty())
                <flux:heading size="sm" class="mt-3">{{ __('Refunds') }}</flux:heading>
                @foreach ($refunds as $tx)
                    <p class="text-sm">{{ ($this->usernameFor)($tx->to_hand_player_id) }} · +{{ number_format($tx->amount) }}</p>
                @endforeach
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Ledger') }}</flux:heading>
            <ul class="mt-4 space-y-1 text-sm">
                @foreach ($this->ledger as $tx)
                    <li wire:key="tx-{{ $tx->id }}" class="flex justify-between gap-2">
                        <span class="text-zinc-500">#{{ $tx->id }} · {{ __($tx->type) }}</span>
                        <span class="font-semibold tabular-nums">{{ number_format($tx->amount) }}</span>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    </div>
</section>
