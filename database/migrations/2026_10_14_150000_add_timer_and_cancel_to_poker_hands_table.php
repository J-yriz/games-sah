<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poker_hands', function (Blueprint $table) {
            // Server-authoritative action timer (nullable = timer off).
            $table->timestamp('turn_started_at')->nullable()->after('completed_at');
            $table->timestamp('turn_deadline_at')->nullable()->after('turn_started_at');
            $table->boolean('turn_extended')->default(false)->after('turn_deadline_at');
            // Misdeal / cancellation audit (nullable = not cancelled).
            $table->text('cancel_reason')->nullable()->after('turn_extended');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancel_reason')
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_key', 64)->nullable()->unique()->after('cancelled_by_user_id');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('action_timer_extension')->default(0)->after('action_timer');
        });

        Schema::table('poker_actions', function (Blueprint $table) {
            $table->index(['hand_id', 'id'], 'poker_actions_hand_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('poker_actions', function (Blueprint $table) {
            $table->dropIndex('poker_actions_hand_sequence');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('action_timer_extension');
        });

        Schema::table('poker_hands', function (Blueprint $table) {
            $table->dropUnique(['cancel_key']);
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn([
                'turn_started_at',
                'turn_deadline_at',
                'turn_extended',
                'cancel_reason',
                'cancel_key',
            ]);
        });
    }
};
