<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('albums')->select('id', 'release_date', 'release_date_components')->orderBy('id')
            ->chunkById(500, function ($albums): void {
                foreach ($albums as $album) {
                    $parts = is_array($album->release_date_components)
                        ? $album->release_date_components
                        : json_decode((string) $album->release_date_components, true);
                    if ((int) ($parts['year'] ?? 0) > 0 || $album->release_date === null) {
                        continue;
                    }

                    [$year, $month, $day] = array_map('intval', explode('-', substr((string) $album->release_date, 0, 10)));
                    DB::table('albums')->where('id', $album->id)->update([
                        'release_date_components' => json_encode(compact('year', 'month', 'day')),
                    ]);
                }
            });

        Schema::table('albums', fn (Blueprint $table) => $table->dropColumn('release_date'));
    }

    public function down(): void
    {
        Schema::table('albums', fn (Blueprint $table) => $table->date('release_date')->nullable());

        DB::table('albums')->select('id', 'release_date_components')->orderBy('id')
            ->chunkById(500, function ($albums): void {
                foreach ($albums as $album) {
                    $parts = json_decode((string) $album->release_date_components, true) ?: [];
                    $year = (int) ($parts['year'] ?? 0);
                    $month = (int) ($parts['month'] ?? 0);
                    $day = (int) ($parts['day'] ?? 0);
                    if ($year > 0 && checkdate($month, $day, $year)) {
                        DB::table('albums')->where('id', $album->id)->update([
                            'release_date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                        ]);
                    }
                }
            });
    }
};
