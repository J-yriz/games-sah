<?php

/*
 * Staging seed helper (isolated staging only, never production).
 * Creates verified users, a room with seats/stacks, and starts a hand.
 * Usage: php scripts/staging-seed.php <tag> (passwords from STAGING_PASSWORD env)
 * Prints JSON: {users, room_id, hand_id}.
 */

use App\Actions\Poker\StartHand;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tag = $argv[1] ?? 'seed';
$password = getenv('STAGING_PASSWORD') ?: throw new RuntimeException('STAGING_PASSWORD required');

$users = [];
foreach (['a', 'b', 'c'] as $who) {
    $users[$who] = User::forceCreate([
        'name' => "Staging {$tag} {$who}",
        'username' => Str::lower("stg_{$tag}_{$who}"),
        'email' => "stg_{$tag}_{$who}@example.test",
        'email_verified_at' => now(),
        'password' => $password,
    ]);
}

$room = Room::forceCreate([
    'host_user_id' => $users['a']->id,
    'name' => "staging-{$tag}",
    'invite_code' => Str::upper(Str::random(8)),
    'game' => 'poker',
    'max_players' => 8,
    'min_buy_in' => 1,
    'small_blind' => 10,
    'big_blind' => 20,
    'action_timer' => 300,
    'status' => Room::STATUS_ACTIVE,
]);

foreach (['a', 'b'] as $i => $who) {
    RoomMember::forceCreate([
        'room_id' => $room->id,
        'user_id' => $users[$who]->id,
        'role' => $who === 'a' ? Room::ROLE_HOST : Room::ROLE_PLAYER,
        'status' => RoomMember::STATUS_ACTIVE,
        'joined_at' => now(),
        'seat' => $i,
    ]);
    TableStack::forceCreate(['room_id' => $room->id, 'user_id' => $users[$who]->id, 'balance' => 1000]);
}

$hand = app(StartHand::class)->handle($users['a'], $room, (string) Str::uuid());

echo json_encode([
    'room_id' => $room->id,
    'hand_id' => $hand->id,
    'users' => [
        'a' => ['id' => $users['a']->id, 'email' => $users['a']->email, 'username' => $users['a']->username],
        'b' => ['id' => $users['b']->id, 'email' => $users['b']->email, 'username' => $users['b']->username],
        'c' => ['id' => $users['c']->id, 'email' => $users['c']->email, 'username' => $users['c']->username],
    ],
])."\n";
