<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedInteger('duration_seconds');
            $table->string('audio_url');
            $table->string('cover_image_url')->nullable();
            $table->unsignedSmallInteger('track_number')->nullable();
            $table->timestamps();

            $table->index(['artist_id', 'album_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX tracks_title_trgm_idx ON tracks USING gin (title gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
