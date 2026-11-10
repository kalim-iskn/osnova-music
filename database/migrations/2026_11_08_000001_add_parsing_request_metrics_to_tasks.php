<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('parsing_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('muzofond_duration_ms')->nullable();
            $table->json('genius_metrics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parsing_tasks', function (Blueprint $table): void {
            $table->dropColumn(['muzofond_duration_ms', 'genius_metrics']);
        });
    }
};
