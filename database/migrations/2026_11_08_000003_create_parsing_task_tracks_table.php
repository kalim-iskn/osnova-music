<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('parsing_task_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parsing_task_id')->constrained('parsing_tasks')->cascadeOnDelete();
            $table->char('source_key', 64);
            $table->string('track_title')->nullable();
            $table->boolean('matched')->default(false);
            $table->boolean('completed')->default(true);
            $table->timestamps();

            $table->unique(['parsing_task_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parsing_task_tracks');
    }
};
