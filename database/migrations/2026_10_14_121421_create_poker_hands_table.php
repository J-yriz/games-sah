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
        Schema::create('poker_hands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('hand_number')->default(1);
            $table->foreignId('dealer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('dealer_seat')->nullable();
            $table->unsignedBigInteger('small_blind_amount');
            $table->unsignedBigInteger('big_blind_amount');
            $table->string('street', 20)->default('preflop')->index();
            $table->string('status', 20)->default('preflop')->index();
            $table->foreignId('current_turn_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('current_bet')->default(0);
            $table->unsignedBigInteger('min_full_raise')->default(0);
            $table->unsignedBigInteger('last_aggressor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('total_pot')->default(0);
            $table->json('pot_layers')->nullable(); // computed on settlement
            $table->unsignedSmallInteger('elapsed_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'hand_number']);
            $table->index(['room_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poker_hands');
    }
};
