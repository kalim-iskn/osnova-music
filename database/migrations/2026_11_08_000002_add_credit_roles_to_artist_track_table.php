<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_track', function (Blueprint $table): void {
            $table->string('credit_role', 32)->default('primary')->after('track_id');
            $table->unsignedSmallInteger('credit_position')->default(0)->after('credit_role');
        });
    }

    public function down(): void
    {
        Schema::table('artist_track', function (Blueprint $table): void {
            $table->dropColumn(['credit_role', 'credit_position']);
        });
    }
};
