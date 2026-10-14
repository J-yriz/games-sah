<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Chip Grant
    |--------------------------------------------------------------------------
    |
    | Chips credited once to every new account wallet. The grant is recorded
    | as an `initial_grant` ledger row so balances always reconcile.
    |
    */

    'initial_grant' => (int) env('CHIPS_INITIAL_GRANT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Transfer Limits
    |--------------------------------------------------------------------------
    */

    'min_transfer' => (int) env('CHIPS_MIN_TRANSFER', 1),

    'max_transfer' => (int) env('CHIPS_MAX_TRANSFER', 1_000_000_000),

    /*
    |--------------------------------------------------------------------------
    | Transfer Rate Limiting
    |--------------------------------------------------------------------------
    */

    'transfer_rate_per_minute' => (int) env('CHIPS_TRANSFER_RATE_PER_MINUTE', 10),

    /*
    |--------------------------------------------------------------------------
    | Room Mutation Rate Limiting
    |--------------------------------------------------------------------------
    */

    'room_rate_per_minute' => (int) env('CHIPS_ROOM_RATE_PER_MINUTE', 30),

];
