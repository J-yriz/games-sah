<?php

namespace App\Console\Commands;

use App\Actions\Poker\ProcessTimeouts;
use Illuminate\Console\Command;

/**
 * Enforce expired poker action timers. Safe under overlapping workers:
 * each hand is locked and re-validated, so only one timeout can consume
 * a given turn. Run every minute via the scheduler (see routes/console.php)
 * with a queue/scheduler worker in production; harmless when idle.
 */
class ProcessPokerTimeouts extends Command
{
    protected $signature = 'poker:process-timeouts {--hand= : Only process a single hand ID}';

    protected $description = 'Apply auto-check/auto-fold for expired poker action timers.';

    public function handle(ProcessTimeouts $timeouts): int
    {
        $handId = $this->option('hand') !== null ? (int) $this->option('hand') : null;

        $summary = $timeouts->handle($handId);

        $this->info(sprintf(
            'Timeouts processed: %d (acted: %d, extended: %d, skipped: %d).',
            $summary['processed'],
            $summary['acted'],
            $summary['extended'],
            $summary['skipped']
        ));

        return self::SUCCESS;
    }
}
