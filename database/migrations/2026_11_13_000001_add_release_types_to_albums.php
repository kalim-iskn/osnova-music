<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->string('genius_album_type')->nullable();
            $table->string('muzofond_album_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropColumn(['genius_album_type', 'muzofond_album_type']);
        });
    }
};
