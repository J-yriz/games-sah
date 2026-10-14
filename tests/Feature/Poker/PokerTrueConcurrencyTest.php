<?php

use Symfony\Component\Process\Process;

test('postgres true-concurrency probe: races serialize without double apply', function () {
    $probe = base_path('scripts/pg-concurrency-probe.php');

    $process = new Process([PHP_BINARY, $probe], base_path(), null, null, 300);
    $process->run();

    expect($process->getExitCode())->toBe(0, 'Probe output: '.$process->getOutput().$process->getErrorOutput());
})->skip(fn () => config('database.default') !== 'pgsql', 'Requires a PostgreSQL connection.');
