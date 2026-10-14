<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abort loudly on conflicting data instead of silently dropping rows.
        $conflicts = DB::table('room_members')
            ->select('room_id', 'seat', DB::raw('COUNT(*) as total'))
            ->whereNotNull('seat')
            ->groupBy('room_id', 'seat')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($conflicts->isNotEmpty()) {
            $details = $conflicts
                ->map(fn ($row): string => "room_id={$row->room_id} seat={$row->seat} rows={$row->total}")
                ->join('; ');

            throw new RuntimeException(
                'Cannot scope room_members.seat per room: duplicate seats exist ['.$details.']. '.
                'Resolve the duplicates manually before migrating.'
            );
        }

        Schema::table('room_members', function (Blueprint $table) {
            $table->dropUnique(['seat']);
            $table->dropIndex(['seat']);
            $table->unique(['room_id', 'seat'], 'room_members_room_seat_unique');
        });
    }

    public function down(): void
    {
        $conflicts = DB::table('room_members')
            ->select('seat', DB::raw('COUNT(*) as total'))
            ->whereNotNull('seat')
            ->groupBy('seat')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($conflicts->isNotEmpty()) {
            $details = $conflicts
                ->map(fn ($row): string => "seat={$row->seat} rows={$row->total}")
                ->join('; ');

            throw new RuntimeException(
                'Cannot restore global unique on room_members.seat: seat reused across rooms ['.$details.'].'
            );
        }

        Schema::table('room_members', function (Blueprint $table) {
            $table->dropUnique('room_members_room_seat_unique');
            $table->unique('seat');
            $table->index('seat');
        });
    }
};
