<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('album_position_inferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('track_number');
            $table->timestamps();
            $table->unique(['album_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_position_inferences');
    }
};
