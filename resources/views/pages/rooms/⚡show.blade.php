<?php

use App\Actions\Chips\BuyInToRoom;
use App\Actions\Chips\CashOutFromRoom;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\RoomIdempotencyConflictException;
use App\Actions\Rooms\Exceptions\StackNotEmptyException;
use App\Actions\Rooms\LeaveRoom;
use App\Actions\Rooms\ManageRoom;
use App\Models\ChipTransaction;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\Wallet;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Room')] class extends Component
{
    public Room $room;

    public string $moveType = 'buy_in';

    public string $moveAmount = '';

    public string $moveOperationId = '';

    public bool $confirmingMove = false;

    public string $statusMessage = '';

    /** @var array<int, string> */
    public array $onlineUsernames = [];

    public string $settName = '';

    public string $settMaxPlayers = '';

    public string $settMinBuyIn = '';

    public string $settMaxBuyIn = '';

    public function mount(Room $room): void
    {
        Gate::authorize('view', $room);

        $this->room = $room;
        $this->moveOperationId = (string) Str::uuid();
        $this->settName = $room->name;
        $this->settMaxPlayers = (string) $room->max_players;
        $this->settMinBuyIn = (string) $room->min_buy_in;
        $this->settMaxBuyIn = $room->max_buy_in === null ? '' : (string) $room->max_buy_in;
    }

    #[Computed]
    public function freshRoom(): Room
    {
        return Room::findOrFail($this->room->id);
    }

    #[Computed]
    public function members(): \Illuminate\Support\Collection
    {
        return RoomMember::where('room_id', $this->room->id)
            ->where('status', RoomMember::STATUS_ACTIVE)
            ->with('user:id,name,username')
            ->orderBy('role')
            ->orderBy('joined_at')
            ->get();
    }

    #[Computed]
    public function stacks(): \Illuminate\Support\Collection
    {
        return TableStack::where('room_id', $this->room->id)
            ->with('user:id,name,username')
            ->orderByDesc('balance')
            ->get();
    }

    #[Computed]
    public function history(): \Illuminate\Support\Collection
    {
        $stackIds = TableStack::where('room_id', $this->room->id)->pluck('id');

        return ChipTransaction::whereIn('from_table_stack_id', $stackIds)
            ->orWhereIn('to_table_stack_id', $stackIds)
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function myStackBalance(): int
    {
        return (int) (TableStack::where('room_id', $this->room->id)->where('user_id', Auth::id())->value('balance') ?? 0);
    }

    #[Computed]
    public function walletBalance(): int
    {
        return (int) (Wallet::where('user_id', Auth::id())->value('balance') ?? 0);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->freshRoom);
    }

    #[Computed]
    public function roomIdForChannel(): int
    {
        return $this->room->id;
    }

    public function startMove(string $type): void
    {
        $this->statusMessage = '';
        $this->moveType = $type;
        $this->confirmingMove = false;
        $this->resetErrorBag();
    }

    public function reviewMove(): void
    {
        $this->statusMessage = '';

        $this->validate([
            'moveType' => ['required', 'string', 'in:buy_in,rebuy,cash_out'],
            'moveAmount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'moveOperationId' => ['required', 'string', 'max:64'],
        ]);

        $this->confirmingMove = true;
    }

    public function cancelMove(): void
    {
        $this->confirmingMove = false;
    }

    public function confirmMove(BuyInToRoom $buyIn, CashOutFromRoom $cashOut): void
    {
        $this->validate([
            'moveType' => ['required', 'string', 'in:buy_in,rebuy,cash_out'],
            'moveAmount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'moveOperationId' => ['required', 'string', 'max:64'],
        ]);

        if (! $this->hitRateLimit()) {
            return;
        }

        $room = $this->freshRoom;

        try {
            $result = $this->moveType === 'cash_out'
                ? $cashOut->handle(Auth::user(), $room, (int) $this->moveAmount, $this->moveOperationId)
                : $buyIn->handle(Auth::user(), $room, (int) $this->moveAmount, $this->moveOperationId, $this->moveType);
        } catch (NotMemberException|InvalidRoomStateException $e) {
            $this->confirmingMove = false;
            $this->addError('moveAmount', __($e->getMessage()));

            return;
        } catch (InsufficientBalanceException|InvalidTransferAmountException $e) {
            $this->confirmingMove = false;
            $this->addError('moveAmount', __($e->getMessage()));

            return;
        } catch (RoomIdempotencyConflictException $e) {
            $this->confirmingMove = false;
            $this->addError('moveAmount', __($e->getMessage()));

            return;
        }

        $this->statusMessage = $result->replayed
            ? __('Already processed. Showing the original transaction.')
            : __('Chips moved successfully.');

        $this->reset(['moveAmount', 'confirmingMove']);
        $this->moveOperationId = (string) Str::uuid();

        unset($this->history, $this->stacks, $this->members, $this->myStackBalance, $this->walletBalance);
        Flux::toast(variant: 'success', text: $this->statusMessage);
    }

    public function saveSettings(ManageRoom $rooms): void
    {
        $this->validate([
            'settName' => ['required', 'string', 'min:3', 'max:60'],
            'settMaxPlayers' => ['required', 'integer', 'min:2', 'max:20'],
            'settMinBuyIn' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'settMaxBuyIn' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        try {
            $room = $rooms->updateSettings(Auth::user(), $this->freshRoom, [
                'name' => trim($this->settName),
                'max_players' => (int) $this->settMaxPlayers,
                'min_buy_in' => (int) $this->settMinBuyIn,
                'max_buy_in' => trim($this->settMaxBuyIn) === '' ? null : (int) $this->settMaxBuyIn,
            ]);
        } catch (InvalidRoomStateException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', __($e->getMessage()));

            return;
        }

        $this->room = $room;
        Flux::toast(variant: 'success', text: __('Room settings saved.'));
    }

    public function startTable(ManageRoom $rooms): void
    {
        try {
            $this->room = $rooms->start(Auth::user(), $this->freshRoom);
        } catch (InvalidRoomStateException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', __($e->getMessage()));
        }
    }

    public function closeTable(ManageRoom $rooms, string $status): void
    {
        try {
            $this->room = $rooms->close(Auth::user(), $this->freshRoom, $status);
        } catch (InvalidRoomStateException|StackNotEmptyException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', __($e->getMessage()));
        }
    }

    public function removeMember(ManageRoom $rooms, int $userId): void
    {
        $player = \App\Models\User::find($userId);

        if ($player === null) {
            return;
        }

        try {
            $rooms->removePlayer(Auth::user(), $this->freshRoom, $player);
        } catch (NotMemberException|InvalidRoomStateException|StackNotEmptyException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', __($e->getMessage()));

            return;
        }

        unset($this->members, $this->stacks);
        Flux::toast(variant: 'success', text: __('Player removed.'));
    }

    public function leave(LeaveRoom $rooms): void
    {
        try {
            $rooms->handle(Auth::user(), $this->freshRoom);
        } catch (NotMemberException|InvalidRoomStateException|StackNotEmptyException $e) {
            $this->addError('settName', __($e->getMessage()));

            return;
        }

        $this->redirectRoute('rooms.index', navigate: true);
    }

    /**
     * Re-read authoritative state. Push events and the poller call this;
     * events never mutate balances directly.
     */
    public function refreshState(): void
    {
        Gate::authorize('view', $this->freshRoom);

        unset($this->history, $this->stacks, $this->members, $this->myStackBalance, $this->walletBalance);
    }

    private function hitRateLimit(): bool
    {
        $key = 'room-mutations:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, (int) config('chips.room_rate_per_minute', 30))) {
            $this->addError('moveAmount', __('Too many attempts. Please try again later.'));

            return false;
        }

        RateLimiter::hit($key);

        return true;
    }
}; ?>

<section class="w-full" wire:poll.15s="refreshState">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ $this->freshRoom->name }}</flux:heading>
            <flux:subheading>
                {{ __('Invite code') }}: <span class="font-mono font-semibold">{{ $this->freshRoom->invite_code }}</span>
                · {{ __($this->freshRoom->status) }}
            </flux:subheading>
        </div>
        <flux:button :href="route('rooms.index')" wire:navigate variant="ghost">{{ __('All rooms') }}</flux:button>
    </div>

    @if ($statusMessage !== '')
        <flux:callout variant="success" class="mt-4" data-test="room-status">{{ $statusMessage }}</flux:callout>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg">{{ __('My table stack') }}</flux:heading>
                <p class="mt-2 text-4xl font-semibold tabular-nums" data-test="stack-balance">{{ number_format($this->myStackBalance) }}</p>
                <flux:text>{{ __('chips on this table') }} · {{ __('wallet') }}: <span class="tabular-nums">{{ number_format($this->walletBalance) }}</span></flux:text>

                <div class="mt-4 flex gap-2">
                    <flux:button wire:click="startMove('buy_in')" variant="ghost" size="sm">{{ __('Buy-in') }}</flux:button>
                    <flux:button wire:click="startMove('rebuy')" variant="ghost" size="sm">{{ __('Rebuy') }}</flux:button>
                    <flux:button wire:click="startMove('cash_out')" variant="ghost" size="sm">{{ __('Cash-out') }}</flux:button>
                </div>

                @if (! $confirmingMove)
                    <form wire:submit="reviewMove" class="mt-4 space-y-4">
                        <flux:input wire:model="moveAmount" :label="__('Amount (chips)')" type="number" min="1" required />
                        <flux:button variant="primary" type="submit" class="w-full" data-test="review-move-button">
                            {{ $moveType === 'cash_out' ? __('Review cash-out') : __('Review :type', ['type' => $moveType]) }}
                        </flux:button>
                    </form>
                @else
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Operation') }}</dt>
                            <dd class="font-medium">{{ $moveType }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">{{ __('Amount') }}</dt>
                            <dd class="font-medium tabular-nums">{{ number_format((int) $moveAmount) }} {{ __('chips') }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 flex gap-3">
                        <flux:button variant="ghost" wire:click="cancelMove" class="flex-1">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="confirmMove" class="flex-1" data-test="confirm-move-button">{{ __('Confirm') }}</flux:button>
                    </div>
                @endif
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Leave') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Cash out your stack first. Hosts can only leave an empty room.') }}</flux:text>
                <flux:button wire:click="leave" variant="danger" class="mt-4 w-full" data-test="leave-room-button">{{ __('Leave room') }}</flux:button>
            </flux:card>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <flux:card>
                <flux:heading size="lg">{{ __('Players') }} ({{ $this->members->count() }}/{{ $this->freshRoom->max_players }})</flux:heading>
                <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->members as $member)
                        <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between py-2">
                            <div>
                                <span class="font-medium">{{ $member->user->username }}</span>
                                @if (in_array($member->user->username, $onlineUsernames, true))
                                    <span class="ml-2 text-xs text-green-600">● {{ __('online') }}</span>
                                @endif
                                <p class="text-sm text-zinc-500">{{ __($member->role) }}</p>
                            </div>
                            @if ($this->canManage && $member->user_id !== auth()->id())
                                <flux:button wire:click="removeMember({{ $member->user_id }})" variant="ghost" size="sm">{{ __('Remove') }}</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Table stacks') }}</flux:heading>
                <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->stacks as $stack)
                        <li wire:key="stack-{{ $stack->id }}" class="flex items-center justify-between py-2">
                            <span class="font-medium">{{ $stack->user->username }}</span>
                            <span class="font-semibold tabular-nums">{{ number_format($stack->balance) }}</span>
                        </li>
                    @empty
                        <flux:text class="mt-2">{{ __('No chips on the table yet.') }}</flux:text>
                    @endforelse
                </ul>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Room activity') }}</flux:heading>
                @if ($this->history->isEmpty())
                    <flux:text class="mt-4">{{ __('No chip movements in this room yet.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->history as $transaction)
                            <li wire:key="rtx-{{ $transaction->id }}" class="flex items-center justify-between py-2">
                                <span>{{ __($transaction->type) }} · {{ $transaction->metadata['username'] ?? '' }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($transaction->amount) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>

            @if ($this->canManage)
                <flux:card>
                    <flux:heading size="lg">{{ __('Host controls') }}</flux:heading>
                    <form wire:submit="saveSettings" class="mt-4 space-y-4">
                        <flux:input wire:model="settName" :label="__('Room name')" type="text" required maxlength="60" />
                        <div class="grid grid-cols-3 gap-3">
                            <flux:input wire:model="settMaxPlayers" :label="__('Max')" type="number" min="2" max="20" required />
                            <flux:input wire:model="settMinBuyIn" :label="__('Min buy-in')" type="number" min="1" required />
                            <flux:input wire:model="settMaxBuyIn" :label="__('Max buy-in')" type="number" min="1" />
                        </div>
                        <flux:button variant="primary" type="submit" class="w-full" data-test="save-settings-button">{{ __('Save settings') }}</flux:button>
                    </form>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($this->freshRoom->status === 'waiting')
                            <flux:button wire:click="startTable" variant="primary" size="sm" data-test="start-room-button">{{ __('Start table') }}</flux:button>
                        @endif
                        @if (in_array($this->freshRoom->status, ['waiting', 'active'], true))
                            <flux:button wire:click="closeTable('completed')" variant="ghost" size="sm" data-test="complete-room-button">{{ __('Complete') }}</flux:button>
                            <flux:button wire:click="closeTable('cancelled')" variant="danger" size="sm" data-test="cancel-room-button">{{ __('Cancel room') }}</flux:button>
                        @endif
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
</section>

@script
<script>
    const roomId = $wire.get('roomIdForChannel');

    if (roomId && window.Echo) {
        window.Echo.private(`rooms.${roomId}`).listen('.room.updated', () => {
            $wire.refreshState();
        });

        window.Echo.join(`rooms.${roomId}`)
            .here((users) => {
                $wire.set('onlineUsernames', users.map((u) => u.info?.username ?? u.username).filter(Boolean));
            })
            .joining((user) => {
                const name = user.info?.username ?? user.username;
                const current = $wire.get('onlineUsernames') ?? [];
                if (name && !current.includes(name)) $wire.set('onlineUsernames', [...current, name]);
            })
            .leaving((user) => {
                const name = user.info?.username ?? user.username;
                const current = $wire.get('onlineUsernames') ?? [];
                $wire.set('onlineUsernames', current.filter((n) => n !== name));
            });
    }
</script>
@endscript
