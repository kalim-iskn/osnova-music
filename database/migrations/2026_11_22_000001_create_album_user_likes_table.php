<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_user_likes', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'album_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_user_likes');
    }
};
