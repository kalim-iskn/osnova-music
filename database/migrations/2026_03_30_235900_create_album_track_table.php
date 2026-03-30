<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('album_track')) {
            return;
        }

        Schema::create('album_track', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['album_id', 'track_id']);
            $table->index(['track_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_track');
    }
};
