<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tracks', 'genius_pageviews')) {
            Schema::table('tracks', function (Blueprint $table): void {
                $table->unsignedInteger('genius_pageviews')->default(0)->after('plays_count');
                $table->index('genius_pageviews');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tracks', 'genius_pageviews')) {
            Schema::table('tracks', function (Blueprint $table): void {
                $table->dropIndex(['genius_pageviews']);
                $table->dropColumn('genius_pageviews');
            });
        }
    }
};
