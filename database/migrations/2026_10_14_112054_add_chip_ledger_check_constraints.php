<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Defense in depth: the application already refuses overdrafts inside
     * locked transactions, but the database itself must reject a negative
     * balance or a non-positive amount on engines that support it.
     *
     * SQLite cannot add CHECK constraints to existing tables, so it is
     * skipped there; application-level validation remains authoritative.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE chip_transactions ADD CONSTRAINT chip_transactions_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE chip_transactions DROP CONSTRAINT chip_transactions_amount_positive');
        DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_balance_non_negative');
    }
};
