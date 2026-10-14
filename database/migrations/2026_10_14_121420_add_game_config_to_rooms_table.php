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
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('small_blind')->default(1)->after('max_buy_in');
            $table->unsignedBigInteger('big_blind')->default(2)->after('small_blind');
            $table->unsignedBigInteger('min_raise_multiplier')->default(2)->after('big_blind');
            // seconds per action, 0 = no timer
            $table->unsignedInteger('action_timer')->default(0)->after('min_raise_multiplier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['small_blind', 'big_blind', 'min_raise_multiplier', 'action_timer']);
        });
    }
};
