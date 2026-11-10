<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('muzofond_title')->nullable();
            $table->string('muzofond_album_title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropColumn(['muzofond_title', 'muzofond_album_title']);
        });
    }
};
