<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropUnique('tracks_genius_id_unique');
            $table->index('genius_id', 'tracks_genius_id_index');
        });
    }

    public function down(): void
    {
        $hasRepeatedIds = DB::table('tracks')
            ->select('genius_id')
            ->whereNotNull('genius_id')
            ->groupBy('genius_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedIds) {
            throw new RuntimeException('Cannot restore unique tracks.genius_id while multiple release tracks share a Genius song ID.');
        }

        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex('tracks_genius_id_index');
            $table->unique('genius_id', 'tracks_genius_id_unique');
        });
    }
};
