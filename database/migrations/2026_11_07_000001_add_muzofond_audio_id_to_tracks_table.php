<?php

use App\Services\TrackParsing\MuzofondAudioIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tracks', 'muzofond_audio_id')) {
            Schema::table('tracks', function (Blueprint $table): void {
                $table->string('muzofond_audio_id', 100)->nullable();
                $table->index('muzofond_audio_id');
            });
        }

        DB::table('tracks')
            ->select(['id', 'muzofond_audio_id', 'audio_url', 'original_link'])
            ->whereNull('muzofond_audio_id')
            ->orderBy('id')
            ->chunkById(500, function ($tracks): void {
                foreach ($tracks as $track) {
                    $audioFileId = MuzofondAudioIdentity::fileIdFromUrl((string) $track->audio_url)
                        ?? MuzofondAudioIdentity::fileIdFromUrl((string) $track->original_link);

                    if ($audioFileId === null) {
                        continue;
                    }

                    DB::table('tracks')
                        ->where('id', $track->id)
                        ->whereNull('muzofond_audio_id')
                        ->update(['muzofond_audio_id' => $audioFileId]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tracks', 'muzofond_audio_id')) {
            Schema::table('tracks', function (Blueprint $table): void {
                $table->dropIndex(['muzofond_audio_id']);
                $table->dropColumn('muzofond_audio_id');
            });
        }
    }
};
