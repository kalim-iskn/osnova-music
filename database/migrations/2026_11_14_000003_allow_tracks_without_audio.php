<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('audio_url')->nullable()->change();
            $table->unsignedInteger('duration_seconds')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unavailable tracks must be resolved or removed before restoring NOT NULL.
        if (\Illuminate\Support\Facades\DB::table('tracks')->whereNull('audio_url')->orWhereNull('duration_seconds')->exists()) {
            throw new \RuntimeException('Cannot restore NOT NULL while unavailable tracks exist.');
        }
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('audio_url')->nullable(false)->change();
            $table->unsignedInteger('duration_seconds')->nullable(false)->change();
        });
    }
};
