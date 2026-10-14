<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Table stacks must never go negative either. Same engine
     * limitation as the wallet checks: SQLite is skipped.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE table_stacks ADD CONSTRAINT table_stacks_balance_non_negative CHECK (balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE table_stacks DROP CONSTRAINT table_stacks_balance_non_negative');
    }
};
