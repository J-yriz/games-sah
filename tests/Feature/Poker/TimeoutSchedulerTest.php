<?php

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\ProcessTimeouts;
use App\Actions\Poker\StartHand;
use App\Models\PokerHandPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

test('timeout sweep is registered on the scheduler', function () {
    $events = collect(Schedule::events())->map(fn ($e) => $e->command ?? '');

    expect($events->filter(fn ($c) => str_contains((string) $c, 'poker:process-timeouts')))->not->toBeEmpty();
});

test('a stuck turn is cleared gracefully instead of blocking the sweep', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    completePreflopNoRaise($hand, $host, $p1, $p2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    // A missing turn holder is handled gracefully (clear + skip), and the
    // sweep survives: delete the player row so the turn dangles logically.
    // (FK prevents a dangling user id, so simulate via status instead.)
    $hand->players()->where('user_id', $hand->current_turn_user_id)->update(['status' => PokerHandPlayer::STATUS_FOLDED]);
    $hand->update(['turn_deadline_at' => now()->subMinute()]);

    $summary = app(ProcessTimeouts::class)->handle($hand->id);

    expect($summary['processed'])->toBe(1)->and($summary['skipped'])->toBe(1);
});

test('overdue deadlines are caught up after a scheduler gap', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = makeTimedRoom($host, [$p1, $p2], 60);

    $hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
    completePreflopNoRaise($hand, $host, $p1, $p2);
    $hand = app(AdvanceStreet::class)->handle($host, $hand);

    // Scheduler was down for an hour; the deadline is long past.
    $hand->update(['turn_deadline_at' => now()->subHour()]);

    $summary = app(ProcessTimeouts::class)->handle();

    expect($summary['acted'])->toBe(1)
        ->and($hand->refresh()->turn_deadline_at->isFuture())->toBeTrue();
});
