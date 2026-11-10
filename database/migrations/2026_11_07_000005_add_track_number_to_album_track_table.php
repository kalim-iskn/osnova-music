<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('album_track', function (Blueprint $table): void {
            $table->unsignedSmallInteger('track_number')->nullable();
        });

        DB::table('album_track')->select(['id', 'track_id'])->orderBy('id')->chunkById(500, function ($rows): void {
            $numbers = DB::table('tracks')->whereIn('id', $rows->pluck('track_id'))->pluck('track_number', 'id');

            foreach ($rows as $row) {
                $number = $numbers[$row->track_id] ?? null;

                if ($number !== null) {
                    DB::table('album_track')->where('id', $row->id)->update(['track_number' => $number]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('album_track', function (Blueprint $table): void {
            $table->dropColumn('track_number');
        });
    }
};
