<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->json('release_date_components')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropColumn('release_date_components');
        });
    }
};
