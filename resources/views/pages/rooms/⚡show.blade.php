<?php

use App\Actions\Chips\BuyInToRoom;
use App\Actions\Chips\CashOutFromRoom;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\Exceptions\PokerException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Actions\Poker\StartHand;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\NotMemberException;
use App\Actions\Rooms\Exceptions\RoomException;
use App\Actions\Rooms\Exceptions\RoomIdempotencyConflictException;
use App\Actions\Rooms\Exceptions\StackNotEmptyException;
use App\Actions\Rooms\LeaveRoom;
use App\Actions\Rooms\ManageRoom;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\Wallet;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    public string $settTimer = '';

    public string $settTimerExt = '';

    public string $betAmount = '';

    public string $raiseTo = '';

    public string $pokerOperationId = '';

    public string $pokerMessage = '';

    /** @var array<int, array<int>> */
    public array $settleWinners = [];

    public string $settleOperationId = '';

    public string $cancelReason = '';

    public bool $confirmingCancel = false;

    public string $cancelOperationId = '';

    public function mount(Room $room): void
    {
        Gate::authorize('view', $room);

        $this->room = $room;
        $this->moveOperationId = (string) Str::uuid();
        $this->pokerOperationId = (string) Str::uuid();
        $this->settleOperationId = (string) Str::uuid();
        $this->cancelOperationId = (string) Str::uuid();
        $this->settName = $room->name;
        $this->settMaxPlayers = (string) $room->max_players;
        $this->settMinBuyIn = (string) $room->min_buy_in;
        $this->settMaxBuyIn = $room->max_buy_in === null ? '' : (string) $room->max_buy_in;
        $this->settTimer = (string) $room->action_timer;
        $this->settTimerExt = (string) $room->action_timer_extension;
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
            $this->addError('moveAmount', $this->safeError($e));

            return;
        } catch (InsufficientBalanceException|InvalidTransferAmountException $e) {
            $this->confirmingMove = false;
            $this->addError('moveAmount', $this->safeError($e));

            return;
        } catch (RoomIdempotencyConflictException $e) {
            $this->confirmingMove = false;
            $this->addError('moveAmount', $this->safeError($e));

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
            'settTimer' => ['required', 'integer', 'min:0', 'max:3600'],
            'settTimerExt' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        try {
            $room = $rooms->updateSettings(Auth::user(), $this->freshRoom, [
                'name' => trim($this->settName),
                'max_players' => (int) $this->settMaxPlayers,
                'min_buy_in' => (int) $this->settMinBuyIn,
                'max_buy_in' => trim($this->settMaxBuyIn) === '' ? null : (int) $this->settMaxBuyIn,
                'action_timer' => (int) $this->settTimer,
                'action_timer_extension' => (int) $this->settTimerExt,
            ]);
        } catch (InvalidRoomStateException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', $this->safeError($e));

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
            $this->addError('settName', $this->safeError($e));
        }
    }

    public function closeTable(ManageRoom $rooms, string $status): void
    {
        try {
            $this->room = $rooms->close(Auth::user(), $this->freshRoom, $status);
        } catch (InvalidRoomStateException|StackNotEmptyException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->addError('settName', $this->safeError($e));
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
            $this->addError('settName', $this->safeError($e));

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
            $this->addError('settName', $this->safeError($e));

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
        unset($this->activeHand, $this->handPlayers, $this->handActions, $this->callAmountOwed);
        unset($this->seatMap, $this->mySeat);
    }

    #[Computed]
    public function activeHand(): ?PokerHand
    {
        return PokerHand::where('room_id', $this->room->id)
            ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
            ->orderByDesc('hand_number')
            ->first();
    }

    #[Computed]
    public function handPlayers(): \Illuminate\Support\Collection
    {
        if ($this->activeHand === null) {
            return collect();
        }

        return PokerHandPlayer::where('hand_id', $this->activeHand->id)
            ->with('user:id,name,username')
            ->orderBy('seat')
            ->get();
    }

    #[Computed]
    public function handActions(): \Illuminate\Support\Collection
    {
        if ($this->activeHand === null) {
            return collect();
        }

        return \App\Models\PokerAction::where('hand_id', $this->activeHand->id)
            ->with('user:id,username')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function callAmountOwed(): int
    {
        $hand = $this->activeHand;
        if ($hand === null) {
            return 0;
        }
        $me = $this->handPlayers->firstWhere('user_id', Auth::id());
        if ($me === null) {
            return 0;
        }

        return max(0, (int) $hand->current_bet - (int) $me->street_contribution);
    }

    #[Computed]
    public function activeHandIdForChannel(): ?int
    {
        return $this->activeHand?->id;
    }

    public function startPokerHand(StartHand $start): void
    {
        $this->pokerMessage = '';
        if (! $this->hitRateLimit()) {
            return;
        }

        try {
            $start->handle(Auth::user(), $this->freshRoom, $this->pokerOperationId);
        } catch (\Throwable $e) {
            $this->addError('pokerOperationId', $this->safeError($e));

            return;
        }

        $this->pokerOperationId = (string) Str::uuid();
        $this->resetValidation();
        unset($this->activeHand, $this->handPlayers, $this->handActions);
    }

    public function pokerAct(PlayerAction $actions, string $action): void
    {
        $this->pokerMessage = '';
        $hand = $this->activeHand;
        if ($hand === null) {
            return;
        }
        if (! $this->hitRateLimit()) {
            return;
        }

        $this->validate([
            'betAmount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'raiseTo' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        try {
            $actions->handle(
                Auth::user(),
                $hand,
                $action,
                $this->pokerOperationId,
                $this->betAmount === '' ? null : (int) $this->betAmount,
                $this->raiseTo === '' ? null : (int) $this->raiseTo,
            );
        } catch (\Throwable $e) {
            $this->addError('pokerOperationId', $this->safeError($e));

            return;
        }

        $this->pokerOperationId = (string) Str::uuid();
        $this->reset(['betAmount', 'raiseTo']);
        $this->resetValidation();
        unset($this->activeHand, $this->handPlayers, $this->handActions, $this->callAmountOwed, $this->history, $this->stacks);
    }

    public function advancePokerStreet(AdvanceStreet $advance): void
    {
        $hand = $this->activeHand;
        if ($hand === null) {
            return;
        }

        try {
            $advance->handle(Auth::user(), $hand);
        } catch (\Throwable $e) {
            $this->addError('pokerOperationId', $this->safeError($e));

            return;
        }

        unset($this->activeHand, $this->handPlayers, $this->handActions);
    }

    public function settlePokerHand(SettleHand $settle): void
    {
        $hand = $this->activeHand;
        if ($hand === null) {
            return;
        }

        // Normalize Livewire checkbox payload: [potIndex => [userId => on/off]].
        $winners = [];
        foreach ((array) $this->settleWinners as $potIndex => $rows) {
            $ids = [];
            foreach ((array) $rows as $userId => $checked) {
                if (is_numeric($checked)) {
                    $ids[] = (int) $checked;
                } elseif ($checked) {
                    $ids[] = (int) $userId;
                }
            }
            $winners[(int) $potIndex] = array_values(array_unique($ids));
        }

        try {
            $settle->handle(Auth::user(), $hand, $winners, $this->settleOperationId);
        } catch (\Throwable $e) {
            $this->addError('settleOperationId', $this->safeError($e));

            return;
        }

        $this->settleOperationId = (string) Str::uuid();
        $this->reset(['settleWinners']);
        unset($this->activeHand, $this->handPlayers, $this->handActions, $this->history, $this->stacks);
        Flux::toast(variant: 'success', text: __('Hand settled.'));
    }

    #[Computed]
    public function seatMap(): array
    {
        $max = (int) $this->freshRoom->max_players;
        $bySeat = $this->members->keyBy('seat');
        $map = [];

        for ($seat = 0; $seat < $max; $seat++) {
            $map[] = ['seat' => $seat, 'member' => $bySeat->get($seat)];
        }

        return $map;
    }

    #[Computed]
    public function mySeat(): ?int
    {
        $seat = $this->members->firstWhere('user_id', Auth::id())?->seat;

        return $seat === null ? null : (int) $seat;
    }

    public function sitDown(?int $seat, \App\Actions\Rooms\TakeSeat $take): void
    {
        try {
            $take->handle(Auth::user(), $this->freshRoom, $seat);
        } catch (\Throwable $e) {
            $this->addError('seatPicker', $this->safeError($e));

            return;
        }

        unset($this->members, $this->activeHand, $this->handPlayers);
    }

    public function standUp(\App\Actions\Rooms\StandUp $stand): void
    {
        try {
            $stand->handle(Auth::user(), $this->freshRoom);
        } catch (\Throwable $e) {
            $this->addError('seatPicker', $this->safeError($e));

            return;
        }

        unset($this->members, $this->activeHand, $this->handPlayers);
    }

    public function askCancel(): void
    {
        $this->confirmingCancel = true;
        $this->resetErrorBag();
    }

    public function abortCancel(): void
    {
        $this->confirmingCancel = false;
        $this->reset(['cancelReason']);
    }

    public function confirmCancel(\App\Actions\Poker\CancelHand $cancel): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:500']]);

        $hand = $this->activeHand;
        if ($hand === null) {
            return;
        }

        try {
            $cancel->handle(Auth::user(), $hand, trim($this->cancelReason), $this->cancelOperationId);
        } catch (\Throwable $e) {
            $this->addError('cancelReason', $this->safeError($e));

            return;
        }

        $this->cancelOperationId = (string) Str::uuid();
        $this->reset(['cancelReason', 'confirmingCancel']);
        unset($this->activeHand, $this->handPlayers, $this->handActions, $this->history, $this->stacks);
        Flux::toast(variant: 'success', text: __('Hand cancelled and contributions refunded.'));
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

    /**
     * User-facing error text. Domain and authorization exceptions carry
     * curated messages; anything else (SQL, unexpected throwables) is
     * logged and replaced so responses never leak internals.
     */
    private function safeError(\Throwable $e): string
    {
        if ($e instanceof PokerException
            || $e instanceof RoomException
            || $e instanceof \InvalidArgumentException
            || $e instanceof AuthorizationException) {
            return $e->getMessage();
        }

        Log::warning('Room page operation failed unexpectedly.', [
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        return __('Something went wrong. Please try again.');
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
                <flux:heading size="lg">{{ __('Seats') }} ({{ __('you') }}: {{ $this->mySeat === null ? __('standing') : '#'.$this->mySeat }})</flux:heading>
                @error('seatPicker')
                    <flux:callout variant="danger" class="mt-2">{{ $message }}</flux:callout>
                @enderror
                <ul class="mt-4 grid grid-cols-2 gap-2">
                    @foreach ($this->seatMap as $slot)
                        <li wire:key="seat-{{ $slot['seat'] }}" class="flex items-center justify-between rounded border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-700" data-test="seat-{{ $slot['seat'] }}">
                            <span class="font-mono">#{{ $slot['seat'] }}</span>
                            @if ($slot['member'] === null)
                                <flux:button wire:click="sitDown({{ $slot['seat'] }})" variant="ghost" size="sm">{{ __('Sit') }}</flux:button>
                            @elseif ($slot['member']->user_id === auth()->id())
                                <flux:button wire:click="standUp" variant="ghost" size="sm">{{ __('Stand') }}</flux:button>
                            @else
                                <span class="font-medium">{{ $slot['member']->user->username }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($this->mySeat === null)
                    <flux:button wire:click="sitDown(null)" variant="primary" size="sm" class="mt-3" data-test="auto-sit-button">{{ __('Auto-sit first free seat') }}</flux:button>
                @endif
            </flux:card>

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
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Poker table') }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <flux:button :href="route('rooms.hands', $this->room->id)" wire:navigate variant="ghost" size="sm" data-test="history-link">{{ __('History') }}</flux:button>
                        @if ($this->activeHand)
                            <flux:badge size="sm">{{ __($this->activeHand->status) }} · {{ __($this->activeHand->street) }}</flux:badge>
                        @endif
                    </div>
                </div>

                @if ($this->activeHand?->turn_deadline_at)
                    <flux:text class="mt-2">
                        {{ __('Time to act') }}:
                        <span data-deadline="{{ $this->activeHand->turn_deadline_at->toIso8601String() }}" data-turn="{{ $this->activeHand->current_turn_user_id }}" data-test="turn-countdown">…</span>
                    </flux:text>
                @endif

                @error('pokerOperationId')
                    <flux:callout variant="danger" class="mt-4">{{ $message }}</flux:callout>
                @enderror
                @error('settleOperationId')
                    <flux:callout variant="danger" class="mt-4">{{ $message }}</flux:callout>
                @enderror

                @if (! $this->activeHand)
                    <flux:text class="mt-4">{{ __('No hand in progress. The host starts a hand; blinds post automatically.') }}</flux:text>
                    @if ($this->canManage)
                        <flux:button wire:click="startPokerHand" variant="primary" size="sm" class="mt-4" data-test="start-hand-button">{{ __('Start hand') }}</flux:button>
                    @endif
                @else
                    <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        <div><dt class="text-zinc-500">{{ __('Pot') }}</dt><dd class="font-semibold tabular-nums" data-test="pot-total">{{ number_format($this->activeHand->total_pot) }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Current bet') }}</dt><dd class="font-semibold tabular-nums">{{ number_format($this->activeHand->current_bet) }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('To call') }}</dt><dd class="font-semibold tabular-nums">{{ number_format($this->callAmountOwed) }}</dd></div>
                    </dl>
                    <flux:text class="mt-2">{{ __('Blinds') }} {{ $this->activeHand->small_blind_amount }}/{{ $this->activeHand->big_blind_amount }} · {{ __('Hand') }} #{{ $this->activeHand->hand_number }}</flux:text>

                    <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->handPlayers as $hp)
                            <li wire:key="hp-{{ $hp->id }}" class="flex items-center justify-between py-2" data-test="hand-player-{{ $hp->user_id }}">
                                <div>
                                    <span class="font-medium">{{ $hp->user->username }}</span>
                                    @if ($hp->is_dealer)<span class="ml-1 rounded bg-amber-200 px-1 text-xs font-bold">D</span>@endif
                                    @if ($hp->is_small_blind)<span class="ml-1 text-xs text-zinc-500">SB</span>@endif
                                    @if ($hp->is_big_blind)<span class="ml-1 text-xs text-zinc-500">BB</span>@endif
                                    @if ($this->activeHand->current_turn_user_id === $hp->user_id)<span class="ml-2 text-xs font-semibold text-green-600">● {{ __('to act') }}</span>@endif
                                    <p class="text-sm text-zinc-500">{{ __($hp->status) }} · {{ __('in') }} {{ number_format($hp->total_contribution) }}</p>
                                </div>
                                <span class="font-semibold tabular-nums">{{ number_format($hp->starting_stack - $hp->total_contribution < 0 ? 0 : $hp->starting_stack - $hp->total_contribution) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->activeHand->current_turn_user_id === auth()->id())
                        <div class="mt-4 flex flex-wrap gap-2" data-test="poker-actions">
                            <flux:button wire:click="pokerAct('check')" variant="ghost" size="sm">{{ __('Check') }}</flux:button>
                            <flux:button wire:click="pokerAct('call')" variant="ghost" size="sm">{{ __('Call') }} {{ number_format($this->callAmountOwed) }}</flux:button>
                            <flux:button wire:click="pokerAct('fold')" variant="danger" size="sm">{{ __('Fold') }}</flux:button>
                            <flux:button wire:click="pokerAct('all_in')" variant="ghost" size="sm">{{ __('All-in') }}</flux:button>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="flex gap-2">
                                <flux:input wire:model="betAmount" :label="__('Bet')" type="number" min="1" />
                                <flux:button wire:click="pokerAct('bet')" variant="primary" size="sm" class="mt-auto">{{ __('Bet') }}</flux:button>
                            </div>
                            <div class="flex gap-2">
                                <flux:input wire:model="raiseTo" :label="__('Raise to')" type="number" min="1" />
                                <flux:button wire:click="pokerAct('raise')" variant="primary" size="sm" class="mt-auto">{{ __('Raise') }}</flux:button>
                            </div>
                        </div>
                        <flux:text class="mt-2">{{ __('Raise uses raise-to: total street contribution after your action.') }}</flux:text>
                    @endif

                    @if ($this->canManage)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if (in_array($this->activeHand->status, ['awaiting_flop', 'awaiting_turn', 'awaiting_river'], true))
                                <flux:button wire:click="advancePokerStreet" variant="primary" size="sm" data-test="advance-street-button">{{ __('Open next street (cards dealt)') }}</flux:button>
                            @endif
                            @if ($this->activeHand->status === 'awaiting_showdown')
                                <flux:button wire:click="settlePokerHand" variant="primary" size="sm" data-test="settle-hand-button">{{ __('Settle hand') }}</flux:button>
                            @endif
                            @if (! $confirmingCancel)
                                <flux:button wire:click="askCancel" variant="danger" size="sm" data-test="cancel-hand-button">{{ __('Misdeal / cancel') }}</flux:button>
                            @endif
                        </div>

                        @if ($confirmingCancel)
                            <form wire:submit="confirmCancel" class="mt-3 space-y-3" data-test="cancel-form">
                                <flux:textarea wire:model="cancelReason" :label="__('Cancellation reason (required)')" rows="2" maxlength="500" required />
                                @error('cancelReason')
                                    <flux:callout variant="danger">{{ $message }}</flux:callout>
                                @enderror
                                <div class="flex gap-2">
                                    <flux:button wire:click="abortCancel" variant="ghost" size="sm" class="flex-1">{{ __('Back') }}</flux:button>
                                    <flux:button variant="danger" type="submit" size="sm" class="flex-1" data-test="confirm-cancel-button">{{ __('Cancel hand & refund') }}</flux:button>
                                </div>
                            </form>
                        @endif

                        @if ($this->activeHand->status === 'awaiting_showdown')
                            <div class="mt-3 space-y-3" data-test="settle-winners">
                                <flux:text>{{ __('Pick winner(s) per pot from eligible players. Physical cards decide.') }}</flux:text>
                                @php
                                    $preview = \App\Support\PotCalculator::calculate(
                                        $this->handPlayers->map(fn ($p) => ['user_id' => $p->user_id, 'seat' => $p->seat, 'total' => (int) $p->total_contribution, 'folded' => $p->status === 'folded'])->toArray(),
                                        (int) $this->activeHand->dealer_seat
                                    );
                                @endphp
                                @foreach ($preview['pots'] as $potIndex => $pot)
                                    <div class="rounded border border-zinc-200 p-3 dark:border-zinc-700">
                                        <p class="text-sm font-semibold">{{ __('Pot') }} {{ $potIndex + 1 }}: {{ number_format($pot['amount']) }}</p>
                                        <div class="mt-2 flex flex-wrap gap-3">
                                            @foreach ($this->handPlayers->whereIn('user_id', $pot['eligible']) as $eligible)
                                                <label class="flex items-center gap-1 text-sm">
                                                    <input type="checkbox" wire:model="settleWinners.{{ $potIndex }}" value="{{ $eligible->user_id }}" />
                                                    {{ $eligible->user->username }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                                @if ($preview['uncalled'] !== null)
                                    <flux:text>{{ __('Uncalled excess returns automatically') }}: {{ number_format($preview['uncalled']['amount']) }}</flux:text>
                                @endif
                            </div>
                        @endif
                    @endif

                    @if ($this->handActions->isNotEmpty())
                        <div class="mt-4">
                            <flux:heading size="sm">{{ __('Recent actions') }}</flux:heading>
                            <ul class="mt-2 space-y-1 text-sm text-zinc-500">
                                @foreach ($this->handActions as $action)
                                    <li wire:key="pa-{{ $action->id }}">{{ $action->user->username ?? '' }} · {{ __($action->action) }} · {{ $action->street }} · {{ number_format($action->amount) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
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
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="settTimer" :label="__('Action timer (s, 0 off)')" type="number" min="0" max="3600" required />
                            <flux:input wire:model="settTimerExt" :label="__('Timeout extension (s)')" type="number" min="0" max="3600" required />
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

    const tickCountdown = () => {
        document.querySelectorAll('[data-deadline]').forEach((el) => {
            const left = Math.max(0, Math.round((new Date(el.dataset.deadline).getTime() - Date.now()) / 1000));
            el.textContent = left > 0 ? `${left}s` : '0s (server deciding)';
        });
    };
    tickCountdown();
    setInterval(tickCountdown, 1000);

    if (roomId && window.Echo) {
        window.Echo.private(`rooms.${roomId}`).listen('.room.updated', () => {
            $wire.refreshState();
        });

        const handId = $wire.get('activeHandIdForChannel');
        if (handId) {
            window.Echo.private(`poker-hands.${handId}`).listen('.poker.hand.updated', () => {
                $wire.refreshState();
            });
        }

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
