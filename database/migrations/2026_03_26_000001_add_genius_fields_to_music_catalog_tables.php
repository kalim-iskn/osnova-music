<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table): void {
            if (! Schema::hasColumn('artists', 'genius_id')) {
                $table->unsignedBigInteger('genius_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('artists', 'description_preview')) {
                $table->text('description_preview')->nullable()->after('image_url');
            }
        });

        Schema::table('albums', function (Blueprint $table): void {
            if (! Schema::hasColumn('albums', 'genius_id')) {
                $table->unsignedBigInteger('genius_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('tracks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tracks', 'genius_id')) {
                $table->unsignedBigInteger('genius_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('tracks', 'language')) {
                $table->string('language', 16)->nullable()->after('genres');
                $table->index('language');
            }

            if (! Schema::hasColumn('tracks', 'description_preview')) {
                $table->text('description_preview')->nullable()->after('language');
            }

            if (! Schema::hasColumn('tracks', 'genius_url')) {
                $table->text('genius_url')->nullable()->after('description_preview');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            if (Schema::hasColumn('tracks', 'genius_url')) {
                $table->dropColumn('genius_url');
            }

            if (Schema::hasColumn('tracks', 'description_preview')) {
                $table->dropColumn('description_preview');
            }

            if (Schema::hasColumn('tracks', 'language')) {
                $table->dropIndex(['language']);
                $table->dropColumn('language');
            }

            if (Schema::hasColumn('tracks', 'genius_id')) {
                $table->dropUnique(['genius_id']);
                $table->dropColumn('genius_id');
            }
        });

        Schema::table('albums', function (Blueprint $table): void {
            if (Schema::hasColumn('albums', 'genius_id')) {
                $table->dropUnique(['genius_id']);
                $table->dropColumn('genius_id');
            }
        });

        Schema::table('artists', function (Blueprint $table): void {
            if (Schema::hasColumn('artists', 'description_preview')) {
                $table->dropColumn('description_preview');
            }

            if (Schema::hasColumn('artists', 'genius_id')) {
                $table->dropUnique(['genius_id']);
                $table->dropColumn('genius_id');
            }
        });
    }
};
