<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('album_source_pages', function (Blueprint $table): void {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_source_pages');
    }
};
