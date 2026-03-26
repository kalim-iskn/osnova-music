<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('audio_hash', 64)->nullable()->after('original_link');
            $table->unsignedSmallInteger('release_year')->nullable()->after('audio_hash');
            $table->json('genres')->nullable()->after('release_year');

            $table->unique('audio_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropUnique(['audio_hash']);
            $table->dropColumn(['audio_hash', 'release_year', 'genres']);
        });
    }
};
