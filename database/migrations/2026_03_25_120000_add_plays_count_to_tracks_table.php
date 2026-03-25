<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->unsignedBigInteger('plays_count')->default(0)->after('is_downloaded');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropColumn('plays_count');
        });
    }
};
