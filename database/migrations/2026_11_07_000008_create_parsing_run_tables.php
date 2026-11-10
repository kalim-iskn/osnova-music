<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('parsing_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->text('source_url');
            $table->string('status', 24)->default('queued')->index();
            $table->string('stage', 48)->default('queued');
            $table->json('options')->nullable();
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('completed_tasks')->default(0);
            $table->unsignedInteger('failed_tasks')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('parsing_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parsing_run_id')->constrained('parsing_runs')->cascadeOnDelete();
            $table->text('artist_url');
            $table->string('artist_slug')->nullable();
            $table->string('artist_name')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->string('stage', 48)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('page_limit')->default(0);
            $table->unsignedInteger('tracks_total')->default(0);
            $table->unsignedInteger('tracks_processed')->default(0);
            $table->unsignedInteger('matched_tracks')->default(0);
            $table->unsignedInteger('unmatched_tracks')->default(0);
            $table->unsignedInteger('saved_tracks')->default(0);
            $table->string('current_track')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['parsing_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parsing_tasks');
        Schema::dropIfExists('parsing_runs');
    }
};
