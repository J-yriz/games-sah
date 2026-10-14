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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 60);
            $table->string('invite_code', 12)->unique();
            $table->string('game', 20)->default('poker');
            $table->unsignedSmallInteger('max_players')->default(8);
            $table->unsignedBigInteger('min_buy_in')->default(1);
            $table->unsignedBigInteger('max_buy_in')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('waiting')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
