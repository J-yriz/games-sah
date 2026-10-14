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
        Schema::create('poker_hand_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hand_id')->constrained('poker_hands')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seat');
            $table->unsignedBigInteger('starting_stack');
            $table->unsignedBigInteger('total_contribution')->default(0);
            $table->unsignedBigInteger('street_contribution')->default(0);
            $table->string('status', 20)->default('active'); // active, folded, all_in, out
            $table->boolean('acted_this_street')->default(false);
            $table->boolean('is_dealer')->default(false);
            $table->boolean('is_small_blind')->default(false);
            $table->boolean('is_big_blind')->default(false);
            $table->timestamps();

            $table->unique(['hand_id', 'user_id']);
            $table->unique(['hand_id', 'seat']);
            $table->index(['hand_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poker_hand_players');
    }
};
