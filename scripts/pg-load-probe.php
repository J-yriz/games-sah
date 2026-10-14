<?php

/*
 * Isolated load/recovery probe (PostgreSQL only, never production).
 *
 * Measures, with real concurrent processes on separate connections:
 *  - player-action latency across R rooms (p50/p95),
 *  - settlement bursts with conservation checks,
 *  - queued broadcast drain across a queue-worker restart.
 *
 * Prints JSON-ish lines. Exit 0 on success. Cleans up its rows.
 */

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\SettleHand;
use App\Actions\Poker\StartHand;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use App\Support\PotCalculator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'pgsql') {
    fwrite(STDERR, "LOAD-FAIL: not pgsql\n");
    exit(1);
}
if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "LOAD-FAIL: no pcntl\n");
    exit(1);
}

$tag = 'pgload'.getmypid().'_'.Str::random(4);
$parentPid = getmypid();
$roomIds = [];
$userIds = [];

register_shutdown_function(function () use (&$roomIds, &$userIds, $parentPid): void {
    if (getmypid() !== $parentPid) {
        return;
    }
    try {
        $handIds = PokerHand::whereIn('room_id', $roomIds)->pluck('id');
        ChipTransaction::whereIn('to_poker_hand_id', $handIds)->delete();
        ChipTransaction::whereIn('from_poker_hand_id', $handIds)->delete();
        foreach ($roomIds as $roomId) {
            Room::whereKey($roomId)->delete();
        }
        foreach ($userIds as $userId) {
            User::whereKey($userId)->delete();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'LOAD-WARN cleanup: '.$e->getMessage()."\n");
    }
});

$rooms = (int) ($argv[1] ?? 5);
$latencies = [];

for ($r = 0; $r < $rooms; $r++) {
    $users = [];
    for ($u = 0; $u < 3; $u++) {
        $user = User::forceCreate([
            'name' => "load $r-$u",
            'username' => Str::lower("{$tag}_r{$r}u{$u}"),
            'email' => "{$tag}_r{$r}u{$u}@example.test",
            'password' => Str::random(32),
        ]);
        $users[] = $user;
        $userIds[] = $user->id;
    }
    [$host, $p1, $p2] = $users;
    $room = Room::forceCreate([
        'host_user_id' => $host->id,
        'name' => "{$tag}-{$r}",
        'invite_code' => Str::upper(Str::random(8)),
        'game' => 'poker',
        'max_players' => 8,
        'min_buy_in' => 1,
        'small_blind' => 10,
        'big_blind' => 20,
        'status' => Room::STATUS_ACTIVE,
    ]);
    $roomIds[] = $room->id;
    foreach ($users as $i => $pl) {
        RoomMember::forceCreate([
            'room_id' => $room->id, 'user_id' => $pl->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE, 'joined_at' => now(), 'seat' => $i,
        ]);
        TableStack::forceCreate(['room_id' => $room->id, 'user_id' => $pl->id, 'balance' => 10000]);
    }
    app(StartHand::class)->handle($host, $room, (string) Str::uuid());
}

// Concurrent betting: one child per room plays its full preflop simultaneously.
$dir = sys_get_temp_dir().'/pgload_'.getmypid();
mkdir($dir);
$pids = [];
foreach ($roomIds as $ri => $roomId) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            DB::purge();
            $room = Room::findOrFail($roomId);
            $members = RoomMember::where('room_id', $roomId)->orderBy('seat')->with('user')->get();
            [$host, $p1, $p2] = $members->map(fn ($m) => $m->user)->all();
            $hand = PokerHand::where('room_id', $roomId)
                ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
                ->firstOrFail();
            $t0 = microtime(true);
            app(PlayerAction::class)->handle($host, $hand, 'call', (string) Str::uuid());
            app(PlayerAction::class)->handle($p1, $hand->refresh(), 'call', (string) Str::uuid());
            app(PlayerAction::class)->handle($p2, $hand->refresh(), 'check', (string) Str::uuid());
            $ms = (microtime(true) - $t0) * 1000 / 3;
            file_put_contents("{$dir}/lat_{$ri}.txt", (string) $ms);
        } catch (Throwable $e) {
            file_put_contents("{$dir}/lat_{$ri}.txt", 'ERR:'.get_class($e).':'.substr($e->getMessage(), 0, 120));
        }
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $s);
}
foreach ($roomIds as $ri => $_) {
    $raw = @file_get_contents("{$dir}/lat_{$ri}.txt");
    if ($raw === false || str_starts_with($raw, 'ERR:')) {
        fwrite(STDERR, "LOAD-FAIL: room $ri: $raw\n");
        exit(1);
    }
    $latencies[] = (float) $raw;
}
sort($latencies);
$p50 = $latencies[(int) (count($latencies) * 0.5)];
$p95 = $latencies[min(count($latencies) - 1, (int) (count($latencies) * 0.95))];
printf("rooms=%d concurrent_preflop_ok=%d action_ms_p50=%.1f p95=%.1f\n", $rooms, count($latencies), $p50, $p95);

// Settlement burst with conservation.
$t0 = microtime(true);
foreach ($roomIds as $roomId) {
    $room = Room::findOrFail($roomId);
    $host = $room->members()->where('role', Room::ROLE_HOST)->first()->user;
    $hand = PokerHand::where('room_id', $roomId)
        ->whereNotIn('status', [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED])
        ->firstOrFail();
    foreach (['flop', 'turn', 'river'] as $street) {
        $hand = app(AdvanceStreet::class)->handle($host, $hand->refresh());
        for ($guard = 0; $guard < 10; $guard++) {
            $fresh = $hand->refresh();
            if (! in_array($fresh->status, [
                PokerHand::STATUS_FLOP, PokerHand::STATUS_TURN, PokerHand::STATUS_RIVER,
            ], true) || $fresh->current_turn_user_id === null) {
                break;
            }
            $actor = User::findOrFail($fresh->current_turn_user_id);
            app(PlayerAction::class)->handle($actor, $fresh, 'check', (string) Str::uuid());
            $hand = $fresh;
        }
    }
    $hand = $hand->refresh();
    $pots = PotCalculator::calculate(
        $hand->players()->get()->map(fn ($p) => [
            'user_id' => $p->user_id, 'seat' => $p->seat,
            'total' => (int) $p->total_contribution,
            'folded' => $p->status === 'folded',
        ])->toArray(),
        (int) $hand->dealer_seat
    );
    $winners = [];
    foreach (array_keys($pots['pots']) as $idx) {
        $winners[$idx] = [$host->id];
    }
    app(SettleHand::class)->handle($host, $hand, $winners, (string) Str::uuid());
    $sum = (int) TableStack::where('room_id', $roomId)->sum('balance');
    if ($sum !== 30000) {
        fwrite(STDERR, "LOAD-FAIL: room $roomId conservation $sum != 30000\n");
        exit(1);
    }
}
printf("settlement_burst_ok=%d burst_ms=%.0f\n", count($roomIds), (microtime(true) - $t0) * 1000);

// Queued broadcasts drain across a worker restart.
$jobsBefore = DB::table('jobs')->count();
printf("queued_jobs_pending=%d\n", $jobsBefore);
if ($jobsBefore < 1) {
    fwrite(STDERR, "LOAD-FAIL: expected queued broadcast jobs\n");
    exit(1);
}
array_map('unlink', glob("{$dir}/*"));
rmdir($dir);
echo "LOAD PROBE PASS\n";
exit(0);
