<?php

use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\Exceptions\RoomFullException;
use App\Actions\Rooms\Exceptions\RoomNotFoundException;
use App\Actions\Rooms\JoinRoom;
use App\Models\Room;
use App\Models\RoomMember;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rooms')] class extends Component
{
    public string $createName = '';

    public string $createMaxPlayers = '8';

    public string $createMinBuyIn = '100';

    public string $createMaxBuyIn = '';

    public string $joinCode = '';

    #[Computed]
    public function myRooms(): \Illuminate\Support\Collection
    {
        return Room::whereHas('members', function ($query): void {
            $query->where('user_id', Auth::id())->where('status', RoomMember::STATUS_ACTIVE);
        })->withCount(['members' => function ($query): void {
            $query->where('status', RoomMember::STATUS_ACTIVE);
        }])->orderByDesc('id')->get();
    }

    public function create(CreateRoom $rooms): void
    {
        if (! $this->hitRateLimit()) {
            return;
        }

        $this->validate([
            'createName' => ['required', 'string', 'min:3', 'max:60'],
            'createMaxPlayers' => ['required', 'integer', 'min:2', 'max:20'],
            'createMinBuyIn' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'createMaxBuyIn' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        try {
            $room = $rooms->handle(Auth::user(), [
                'name' => trim($this->createName),
                'max_players' => (int) $this->createMaxPlayers,
                'min_buy_in' => (int) $this->createMinBuyIn,
                'max_buy_in' => trim($this->createMaxBuyIn) === '' ? null : (int) $this->createMaxBuyIn,
            ]);
        } catch (InvalidRoomStateException $e) {
            $this->addError('createName', __($e->getMessage()));

            return;
        }

        $this->redirectRoute('rooms.show', ['room' => $room->id], navigate: true);
    }

    public function join(JoinRoom $rooms): void
    {
        if (! $this->hitRateLimit()) {
            return;
        }

        $this->validate(['joinCode' => ['required', 'string', 'min:4', 'max:12']]);

        try {
            $member = $rooms->handle(Auth::user(), $this->joinCode);
        } catch (RoomNotFoundException) {
            $this->addError('joinCode', __('Invite code not found.'));

            return;
        } catch (InvalidRoomStateException|RoomFullException $e) {
            $this->addError('joinCode', __($e->getMessage()));

            return;
        }

        $this->redirectRoute('rooms.show', ['room' => $member->room_id], navigate: true);
    }

    private function hitRateLimit(): bool
    {
        $key = 'room-mutations:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, (int) config('chips.room_rate_per_minute', 30))) {
            $this->addError('joinCode', __('Too many attempts. Please try again later.'));

            return false;
        }

        RateLimiter::hit($key);

        return true;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Rooms') }}</flux:heading>
    <flux:subheading>{{ __('Create a table or join one with an invite code. Cards stay physical.') }}</flux:subheading>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg">{{ __('Create room') }}</flux:heading>
                <form wire:submit="create" class="mt-4 space-y-4">
                    <flux:input wire:model="createName" :label="__('Room name')" type="text" required maxlength="60" />
                    <flux:input wire:model="createMaxPlayers" :label="__('Max players (2-20)')" type="number" min="2" max="20" required />
                    <flux:input wire:model="createMinBuyIn" :label="__('Minimum buy-in')" type="number" min="1" required />
                    <flux:input wire:model="createMaxBuyIn" :label="__('Maximum buy-in (optional)')" type="number" min="1" />
                    <flux:button variant="primary" type="submit" class="w-full" data-test="create-room-button">
                        {{ __('Create room') }}
                    </flux:button>
                </form>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Join room') }}</flux:heading>
                <form wire:submit="join" class="mt-4 space-y-4">
                    <flux:input wire:model="joinCode" :label="__('Invite code')" type="text" required autocomplete="off" />
                    <flux:button variant="primary" type="submit" class="w-full" data-test="join-room-button">
                        {{ __('Join room') }}
                    </flux:button>
                </form>
            </flux:card>
        </div>

        <div class="lg:col-span-2">
            <flux:card>
                <flux:heading size="lg">{{ __('My rooms') }}</flux:heading>
                @if ($this->myRooms->isEmpty())
                    <flux:text class="mt-4">{{ __('No rooms yet. Create one or join with a code.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->myRooms as $room)
                            <li wire:key="room-{{ $room->id }}" class="flex items-center justify-between py-3">
                                <div>
                                    <a href="{{ route('rooms.show', $room) }}" wire:navigate class="font-medium hover:underline">
                                        {{ $room->name }}
                                    </a>
                                    <p class="text-sm text-zinc-500">
                                        {{ $room->members_count }}/{{ $room->max_players }} {{ __('players') }} · {{ __($room->status) }}
                                    </p>
                                </div>
                                <flux:button :href="route('rooms.show', $room)" wire:navigate variant="ghost" size="sm">
                                    {{ __('Open') }}
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>
        </div>
    </div>
</section>
