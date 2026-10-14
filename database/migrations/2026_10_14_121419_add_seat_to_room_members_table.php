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
        Schema::table('room_members', function (Blueprint $table) {
            // Seat position 0-based, unique per room. NULL means not seated.
            $table->unsignedSmallInteger('seat')->nullable()->unique()->after('status');
            $table->index('seat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropUnique(['seat']);
            $table->dropIndex(['seat']);
            $table->dropColumn('seat');
        });
    }
};
