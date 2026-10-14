<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chip_transactions', function (Blueprint $table) {
            $table->foreignId('from_table_stack_id')->nullable()->after('from_wallet_id')
                ->constrained('table_stacks')->nullOnDelete();
            $table->foreignId('to_table_stack_id')->nullable()->after('to_wallet_id')
                ->constrained('table_stacks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chip_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_table_stack_id');
            $table->dropConstrainedForeignId('to_table_stack_id');
        });
    }
};
