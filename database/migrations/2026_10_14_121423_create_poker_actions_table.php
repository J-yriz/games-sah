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
        Schema::create('poker_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hand_id')->constrained('poker_hands')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('street', 20);
            $table->string('action', 20); // post_blind, check, call, bet, raise, fold, all_in
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('raise_to')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['hand_id', 'created_at']);
            $table->index(['hand_id', 'street']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poker_actions');
    }
};
