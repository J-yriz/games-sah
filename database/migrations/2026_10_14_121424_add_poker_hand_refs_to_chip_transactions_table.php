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
            $table->foreignId('from_poker_hand_id')->nullable()->after('from_table_stack_id')
                ->constrained('poker_hands')->nullOnDelete();
            $table->foreignId('to_poker_hand_id')->nullable()->after('to_table_stack_id')
                ->constrained('poker_hands')->nullOnDelete();
            $table->foreignId('from_hand_player_id')->nullable()->after('from_poker_hand_id')
                ->constrained('poker_hand_players')->nullOnDelete();
            $table->foreignId('to_hand_player_id')->nullable()->after('to_poker_hand_id')
                ->constrained('poker_hand_players')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chip_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_poker_hand_id');
            $table->dropConstrainedForeignId('to_poker_hand_id');
            $table->dropConstrainedForeignId('from_hand_player_id');
            $table->dropConstrainedForeignId('to_hand_player_id');
        });
    }
};
