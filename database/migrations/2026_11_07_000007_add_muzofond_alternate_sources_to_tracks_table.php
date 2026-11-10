<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->json('muzofond_alternate_sources')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropColumn('muzofond_alternate_sources');
        });
    }
};
