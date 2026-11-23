<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('genius_proxies', function (Blueprint $table): void {
            $table->id();
            $table->string('address')->unique();
            $table->boolean('working')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('failed_until')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genius_proxies');
    }
};
