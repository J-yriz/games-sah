<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poker action timers are server-authoritative; this sweep applies
// auto-check/auto-fold for expired turns. Per-hand locking makes
// overlapping workers safe (only one timeout consumes a turn).
// withoutOverlapping + onOneServer coordinate multi-node deployments, but
// only when the cache is shared (database/redis/memcached) — never the
// array or file drivers. Single-node cron is the default assumption.
Schedule::command('poker:process-timeouts')->everyMinute()->withoutOverlapping(10)->onOneServer();
