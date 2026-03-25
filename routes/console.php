<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('app:about', function (): void {
    $this->info('WaveFlow is ready.');
})->purpose('Display a quick project status line.');
