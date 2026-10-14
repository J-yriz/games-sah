<?php

/*
 * True-concurrency probe for the poker betting engine.
 *
 * Runs ONLY against PostgreSQL (refuses any other driver) with real OS
 * processes racing the same hand. Each child uses its own DB connection.
 *
 * Scenarios:
 *  A. Two processes play the SAME turn action simultaneously:
 *     exactly one must succeed, the other must lose with a domain
 *     exception (never a deadlock, never a double apply).
 *  B. Two processes settle the SAME hand with different keys:
 *     exactly one payout, the loser gets "already settled".
 *  C. Two processes start a hand with the SAME idempotency key:
 *     both must return the same hand (no double blinds).
 *
 * Exit 0 = all scenarios pass. Any other exit = probe failed.
 * Cleans up all rows it creates.
 */

use App\Actions\Poker\AdvanceStreet;
use App\Actions\Poker\CancelHand;
use App\Actions\Poker\Exceptions\InvalidActionException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\NotYourTurnException;
use App\Actions\Poker\PlayerAction;
use App\Actions\Poker\ProcessTimeouts;
use App\Actions\Poker\SettleHand;
use App\Actions\Poker\StartHand;
use App\Actions\Rooms\Exceptions\InvalidRoomStateException;
use App\Actions\Rooms\TakeSeat;
use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function probeFail(string $message): never
{
    fwrite(STDERR, "PROBE-FAIL: {$message}\n");
    exit(1);
}

function probeLog(string $message): void
{
    fwrite(STDOUT, $message."\n");
}

if (config('database.default') !== 'pgsql') {
    probeFail('refusing to run: default DB connection is not pgsql');
}

if (! function_exists('pcntl_fork')) {
    probeFail('refusing to run: pcntl unavailable');
}

$tag = 'pgprobe'.getmypid().'_'.Str::random(6);
$parentPid = getmypid();
$createdUserIds = [];
$createdRoomIds = [];

$cleanup = function () use (&$createdUserIds, &$createdRoomIds, $parentPid): void {
    if (getmypid() !== $parentPid) {
        return;
    }
    try {
        $handIds = PokerHand::whereIn('room_id', $createdRoomIds)->pluck('id');
        if ($handIds->isNotEmpty()) {
            ChipTransaction::whereIn('to_poker_hand_id', $handIds)->delete();
            ChipTransaction::whereIn('from_poker_hand_id', $handIds)->delete();
        }
        foreach ($createdRoomIds as $roomId) {
            Room::whereKey($roomId)->delete();
        }
        foreach ($createdUserIds as $userId) {
            User::whereKey($userId)->delete();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'PROBE-WARN cleanup: '.$e->getMessage()."\n");
    }
};
register_shutdown_function($cleanup);

function makePlayer(string $tag, string $who, array &$createdUserIds): User
{
    $user = User::forceCreate([
        'name' => $who,
        'username' => Str::lower($tag.'_'.$who),
        'email' => $tag.'_'.$who.'@example.test',
        'password' => Str::random(32),
    ]);
    $createdUserIds[] = $user->id;

    return $user;
}

function makeRoom(string $tag, User $host, array $players, array &$createdRoomIds): Room
{
    $room = Room::forceCreate([
        'host_user_id' => $host->id,
        'name' => $tag,
        'invite_code' => Str::upper(Str::random(8)),
        'game' => 'poker',
        'max_players' => 8,
        'min_buy_in' => 1,
        'small_blind' => 10,
        'big_blind' => 20,
        'status' => Room::STATUS_ACTIVE,
    ]);
    $createdRoomIds[] = $room->id;

    $all = array_merge([$host], $players);
    foreach ($all as $i => $player) {
        RoomMember::forceCreate([
            'room_id' => $room->id,
            'user_id' => $player->id,
            'role' => $i === 0 ? Room::ROLE_HOST : Room::ROLE_PLAYER,
            'status' => RoomMember::STATUS_ACTIVE,
            'joined_at' => now(),
            'seat' => $i,
        ]);
        TableStack::forceCreate(['room_id' => $room->id, 'user_id' => $player->id, 'balance' => 1000]);
    }

    return $room;
}

/**
 * Run $fn in two child processes behind a file barrier.
 *
 * @return array<int, array{ok: bool, error: ?string, message: ?string}>
 */
function raceTwice(string $dir, Closure $fn): array
{
    $barrier = $dir.'/go';
    @unlink($barrier);
    $pids = [];

    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            probeFail('fork failed');
        }
        if ($pid === 0) {
            // Child: fresh connection, wait for the barrier, run, report.
            try {
                DB::purge();
                DB::statement("SET statement_timeout = '15s'");
                $deadline = microtime(true) + 15;
                while (! file_exists($barrier) && microtime(true) < $deadline) {
                    usleep(5000);
                }
                $fn($i);
                file_put_contents($dir."/result_{$i}.json", json_encode(['ok' => true]));
            } catch (Throwable $e) {
                file_put_contents($dir."/result_{$i}.json", json_encode([
                    'ok' => false,
                    'error' => get_class($e),
                    'message' => substr($e->getMessage(), 0, 300),
                ]));
            }
            exit(0);
        }
        $pids[] = $pid;
    }

    touch($barrier);
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    for ($i = 0; $i < 2; $i++) {
        $raw = @file_get_contents($dir."/result_{$i}.json");
        if ($raw === false) {
            probeFail("child {$i} produced no result file");
        }
        $results[$i] = json_decode($raw, true);
    }

    return $results;
}

$workdir = sys_get_temp_dir().'/pgprobe_'.getmypid();
mkdir($workdir);

// ---------------- Scenario A: same-turn action race ----------------
$host = makePlayer($tag, 'host', $createdUserIds);
$p1 = makePlayer($tag, 'p1', $createdUserIds);
$p2 = makePlayer($tag, 'p2', $createdUserIds);
$room = makeRoom($tag, $host, [$p1, $p2], $createdRoomIds);
$hand = app(StartHand::class)->handle($host, $room, (string) Str::uuid());
$handId = $hand->id;
$hostId = $host->id;

$results = raceTwice($workdir, function (int $i) use ($handId, $hostId): void {
    $h = PokerHand::findOrFail($handId);
    $player = User::findOrFail($hostId);
    app(PlayerAction::class)->handle($player, $h, 'call', (string) Str::uuid());
});

$wins = count(array_filter($results, fn ($r): bool => $r['ok']));
$losses = $results;
probeLog('scenario A (same-turn race): wins='.$wins.' details='.json_encode($losses));

if ($wins !== 1) {
    probeFail("scenario A: expected exactly 1 winner, got {$wins}");
}

$loser = $losses[0]['ok'] ? $losses[1] : $losses[0];
$allowedLoser = [
    NotYourTurnException::class,
    InvalidActionException::class,
    InvalidHandStateException::class,
];
if (! in_array($loser['error'], $allowedLoser, true)) {
    probeFail('scenario A: loser error not a domain exception: '.json_encode($loser));
}

// Exactly one call ledger row from this race (plus blind rows from setup).
$calls = ChipTransaction::where('to_poker_hand_id', $handId)
    ->where('type', ChipTransaction::TYPE_CALL)
    ->count();
if ($calls !== 1) {
    probeFail("scenario A: expected 1 call ledger row, got {$calls}");
}
probeLog('scenario A: PASS');

// ---------------- Scenario B: settlement race ----------------
// Finish the hand sequentially, then race two settlements.
$hand = PokerHand::findOrFail($handId);
$state = $hand->refresh();
// Complete preflop: p1 (SB) calls, p2 (BB) checks. Host already called.
app(PlayerAction::class)->handle($p1, $hand, 'call', (string) Str::uuid());
app(PlayerAction::class)->handle($p2, $hand, 'check', (string) Str::uuid());
foreach (['flop', 'turn', 'river'] as $street) {
    $hand = app(AdvanceStreet::class)->handle($host, $hand->refresh());
    foreach ([$p1, $p2, $host] as $pl) {
        $fresh = $hand->refresh();
        if ($fresh->current_turn_user_id === $pl->id) {
            app(PlayerAction::class)->handle($pl, $fresh, 'check', (string) Str::uuid());
        }
    }
}
$hand = $hand->refresh();
if ($hand->status !== PokerHand::STATUS_AWAITING_SHOWDOWN) {
    probeFail('scenario B setup: hand not at showdown, status='.$hand->status);
}

$stackTotalBeforeSettle = (int) TableStack::where('room_id', $room->id)->sum('balance');
$winnersPayload = [0 => [$host->id]];

$results = raceTwice($workdir, function (int $i) use ($handId, $hostId, $winnersPayload): void {
    $h = PokerHand::findOrFail($handId);
    $hostUser = User::findOrFail($hostId);
    app(SettleHand::class)->handle($hostUser, $h, $winnersPayload, (string) Str::uuid());
});

$wins = count(array_filter($results, fn ($r): bool => $r['ok']));
probeLog('scenario B (settlement race): wins='.$wins.' details='.json_encode($results));

if ($wins !== 1) {
    probeFail("scenario B: expected exactly 1 settler, got {$wins}");
}

$loser = $results[0]['ok'] ? $results[1] : $results[0];
if ($loser['error'] !== InvalidHandStateException::class) {
    probeFail('scenario B: loser must see already-settled, got '.json_encode($loser));
}

$payouts = ChipTransaction::where('from_poker_hand_id', $handId)
    ->where('type', ChipTransaction::TYPE_POT_WIN)
    ->count();
if ($payouts < 1) {
    probeFail('scenario B: no pot_win ledger rows found');
}
$stackTotalAfter = (int) TableStack::where('room_id', $room->id)->sum('balance');
$contrib = (int) ChipTransaction::where('to_poker_hand_id', $handId)->sum('amount');
if ($stackTotalAfter !== $stackTotalBeforeSettle + $contrib) {
    probeFail("scenario B: conservation broken (stacks {$stackTotalBeforeSettle} -> {$stackTotalAfter}, in-escrow {$contrib})");
}
probeLog('scenario B: PASS');

// ---------------- Scenario C: same-key start race ----------------
$room2 = makeRoom($tag.'b', $host, [$p1, $p2], $createdRoomIds);
$sameKey = (string) Str::uuid();
$room2Id = $room2->id;

$results = raceTwice($workdir, function (int $i) use ($room2Id, $hostId, $sameKey, $workdir): void {
    $rm = Room::findOrFail($room2Id);
    $hostUser = User::findOrFail($hostId);
    $h = app(StartHand::class)->handle($hostUser, $rm, $sameKey);
    file_put_contents($workdir."/hand_{$i}.txt", (string) $h->id);
});

$handA = trim((string) @file_get_contents($workdir.'/hand_0.txt'));
$handB = trim((string) @file_get_contents($workdir.'/hand_1.txt'));
probeLog('scenario C (same-key start race): ok='.json_encode(array_column($results, 'ok'))." hands={$handA},{$handB}");

if (! $results[0]['ok'] || ! $results[1]['ok'] || $handA !== $handB) {
    probeFail('scenario C: same-key starts must both return the same hand');
}

$blindCount = PokerHand::findOrFail((int) $handA)->actions()->where('action', 'post_blind')->count();
$room2Hands = PokerHand::where('room_id', $room2Id)->count();
if ($blindCount !== 2 || $room2Hands !== 1) {
    probeFail("scenario C: double blinds detected (blinds={$blindCount}, hands={$room2Hands})");
}
probeLog('scenario C: PASS');

// ---------------- Scenario D: last-seat race ----------------
$roomD = makeRoom($tag.'d', $host, [$p1, $p2], $createdRoomIds);
$roomD->update(['max_players' => 2]);
RoomMember::where('room_id', $roomD->id)->where('user_id', $host->id)->update(['seat' => 0]);
RoomMember::where('room_id', $roomD->id)->whereIn('user_id', [$p1->id, $p2->id])->update(['seat' => null]);
$roomDId = $roomD->id;
$p1Id = $p1->id;
$p2Id = $p2->id;

$results = raceTwice($workdir, function (int $i) use ($roomDId, $p1Id, $p2Id): void {
    $rm = Room::findOrFail($roomDId);
    $user = User::findOrFail($i === 0 ? $p1Id : $p2Id);
    app(TakeSeat::class)->handle($user, $rm, null);
});

$wins = count(array_filter($results, fn ($r): bool => $r['ok']));
$seat1Count = RoomMember::where('room_id', $roomDId)->where('seat', 1)->count();
probeLog('scenario D (last-seat race): wins='.$wins.' seat1='.$seat1Count.' details='.json_encode($results));

if ($wins !== 1 || $seat1Count !== 1) {
    probeFail('scenario D: exactly one sitter must win the last seat');
}
$loser = $results[0]['ok'] ? $results[1] : $results[0];
if ($loser['error'] !== InvalidRoomStateException::class) {
    probeFail('scenario D: loser must see no-free-seats, got '.json_encode($loser));
}
probeLog('scenario D: PASS');

// ---------------- Scenario E: timeout vs manual action ----------------
$roomE = makeRoom($tag.'e', $host, [$p1, $p2], $createdRoomIds);
$roomE->update(['action_timer' => 60, 'action_timer_extension' => 0]);
$handE = app(StartHand::class)->handle($host, $roomE, (string) Str::uuid());
$handE->update(['turn_deadline_at' => now()->subMinute()]);
$handEId = $handE->id;
$manualKey = (string) Str::uuid();

$results = raceTwice($workdir, function (int $i) use ($handEId, $hostId, $manualKey): void {
    if ($i === 0) {
        app(ProcessTimeouts::class)->handle($handEId);
    } else {
        $h = PokerHand::findOrFail($handEId);
        $player = User::findOrFail($hostId);
        app(PlayerAction::class)->handle($player, $h, 'call', $manualKey);
    }
});

$timeoutActs = PokerAction::where('hand_id', $handEId)->get()
    ->filter(fn ($a): bool => (bool) ($a->metadata['timeout'] ?? false))->count();
$manualActs = PokerAction::where('hand_id', $handEId)
    ->where('idempotency_key', $manualKey)->count();
probeLog("scenario E (timeout vs action): timeouts={$timeoutActs} manual={$manualActs} results=".json_encode($results));

if ($timeoutActs + $manualActs !== 1) {
    probeFail('scenario E: exactly one transition must consume the turn');
}
foreach ($results as $r) {
    if (! $r['ok'] && ! in_array($r['error'], [
        NotYourTurnException::class,
        InvalidActionException::class,
        InvalidHandStateException::class,
    ], true)) {
        probeFail('scenario E: unexpected loser error '.json_encode($r));
    }
}
probeLog('scenario E: PASS');

// ---------------- Scenario F: cancel vs settle ----------------
$roomF = makeRoom($tag.'f', $host, [$p1, $p2], $createdRoomIds);
$handF = app(StartHand::class)->handle($host, $roomF, (string) Str::uuid());
// Drive to showdown sequentially.
app(PlayerAction::class)->handle($host, $handF, 'call', (string) Str::uuid());
app(PlayerAction::class)->handle($p1, $handF, 'call', (string) Str::uuid());
app(PlayerAction::class)->handle($p2, $handF, 'check', (string) Str::uuid());
foreach (['flop', 'turn', 'river'] as $street) {
    $handF = app(AdvanceStreet::class)->handle($host, $handF->refresh());
    foreach ([$p1, $p2, $host] as $pl) {
        $fresh = $handF->refresh();
        if ($fresh->current_turn_user_id === $pl->id) {
            app(PlayerAction::class)->handle($pl, $fresh, 'check', (string) Str::uuid());
        }
    }
}
$handFId = $handF->refresh()->id;
if ($handF->status !== PokerHand::STATUS_AWAITING_SHOWDOWN) {
    probeFail('scenario F setup: hand not at showdown');
}

$results = raceTwice($workdir, function (int $i) use ($handFId, $hostId): void {
    $h = PokerHand::findOrFail($handFId);
    $hostUser = User::findOrFail($hostId);
    if ($i === 0) {
        app(CancelHand::class)->handle($hostUser, $h, 'probe misdeal', (string) Str::uuid());
    } else {
        app(SettleHand::class)->handle($hostUser, $h, [0 => [$hostId]], (string) Str::uuid());
    }
});

$finalStatus = PokerHand::findOrFail($handFId)->status;
$finalStacks = (int) TableStack::where('room_id', $roomF->id)->sum('balance');
probeLog("scenario F (cancel vs settle): status={$finalStatus} stacks={$finalStacks} results=".json_encode($results));

$okCount = count(array_filter($results, fn ($r): bool => $r['ok']));
if ($okCount !== 1 || ! in_array($finalStatus, [PokerHand::STATUS_SETTLED, PokerHand::STATUS_CANCELLED], true)) {
    probeFail('scenario F: exactly one terminal transition must win');
}
if ($finalStacks !== 3000) {
    probeFail("scenario F: conservation broken, stacks={$finalStacks}");
}
probeLog('scenario F: PASS');

array_map('unlink', glob($workdir.'/*'));
rmdir($workdir);

probeLog('ALL PROBE SCENARIOS PASS');
exit(0);
