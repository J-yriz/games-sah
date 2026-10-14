<?php

use App\Models\PokerHand;
use App\Models\Room;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Hand history')] class extends Component
{
    use WithPagination;

    public Room $room;

    public function mount(Room $room): void
    {
        Gate::authorize('viewHistory', $room);

        $this->room = $room;
    }

    #[Computed]
    public function hands(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        Gate::authorize('viewHistory', $this->room);

        return PokerHand::where('room_id', $this->room->id)
            ->with(['players.user:id,name,username'])
            ->orderByDesc('hand_number')
            ->paginate(15);
    }

    #[Computed]
    public function winnersFor(): \Closure
    {
        return function (PokerHand $hand): string {
            $names = \App\Models\ChipTransaction::where('from_poker_hand_id', $hand->id)
                ->where('type', \App\Models\ChipTransaction::TYPE_POT_WIN)
                ->with('toHandPlayer.user:id,username')
                ->get()
                ->map(fn ($tx) => $tx->toHandPlayer?->user?->username)
                ->filter()
                ->unique()
                ->values();

            return $names->isEmpty() ? '—' : $names->join(', ');
        };
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Hand history') }}</flux:heading>
            <flux:subheading>{{ $room->name }}</flux:subheading>
        </div>
        <flux:button :href="route('rooms.show', $room)" wire:navigate variant="ghost">{{ __('Back to table') }}</flux:button>
    </div>

    <flux:card class="mt-6">
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->hands as $hand)
                <li wire:key="hand-{{ $hand->id }}" class="flex items-center justify-between gap-3 py-3">
                    <div>
                        <a href="{{ route('rooms.hands.show', [$room, $hand]) }}" wire:navigate class="font-semibold" data-test="hand-{{ $hand->hand_number }}">
                            {{ __('Hand') }} #{{ $hand->hand_number }}
                        </a>
                        <p class="text-sm text-zinc-500">
                            {{ __($hand->status) }} · {{ __($hand->street) }} ·
                            {{ $hand->started_at?->format('Y-m-d H:i') ?? '—' }} →
                            {{ $hand->completed_at?->format('Y-m-d H:i') ?? __('open') }}
                        </p>
                        <p class="text-sm text-zinc-500">
                            {{ __('Winners') }}: {{ ($this->winnersFor)($hand) }}
                            @if ($hand->cancel_reason) · {{ __('Cancelled') }}: {{ $hand->cancel_reason }} @endif
                        </p>
                    </div>
                    <span class="font-semibold tabular-nums" data-test="hand-pot-{{ $hand->hand_number }}">{{ number_format($hand->total_pot) }}</span>
                </li>
            @empty
                <flux:text class="py-4">{{ __('No hands played in this room yet.') }}</flux:text>
            @endforelse
        </ul>
        <div class="mt-4">{{ $this->hands->links() }}</div>
    </flux:card>
</section>
