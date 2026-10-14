<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poker_hands', function (Blueprint $table) {
            $table->string('settlement_key', 64)->nullable()->unique()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('poker_hands', function (Blueprint $table) {
            $table->dropUnique(['settlement_key']);
            $table->dropColumn('settlement_key');
        });
    }
};
