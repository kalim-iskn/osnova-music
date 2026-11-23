<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('app:about', function (): void {
    $this->info(config('app.name').' is ready.');
})->purpose('Display a quick project status line.');

Schedule::command('genius:refresh-proxies')->everyThirtyMinutes()->withoutOverlapping(25);

// Keep the normal 30-minute refresh; do a small refill only while the pool is empty.
Schedule::command('genius:refresh-proxies --refill')->everyFiveMinutes()
    ->when(function (): bool {
        if (! config('services.genius.proxy_enabled', true) || now()->minute % 30 === 0) {
            return false;
        }
        return ! DB::table('genius_proxies')->where('working', true)
            ->whereNull('last_error')
            ->where('checked_at', '>=', now()->subHours(2))
            ->where(fn ($q) => $q->whereNull('failed_until')->orWhere('failed_until', '<=', now()))
            ->exists();
    })->withoutOverlapping(25);
