<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AudioHasher
{
    public function hashRemote(string $url, array $headers = []): ?string
    {
        $tempDirectory = storage_path('app/tmp');
        $tempPath = $tempDirectory . '/' . Str::uuid() . '.bin';

        File::ensureDirectoryExists($tempDirectory);

        try {
            $response = Http::timeout(180)
                ->retry(1, 700)
                ->withHeaders($headers)
                ->withOptions([
                    'sink' => $tempPath,
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'verify' => false,
                ])
                ->get($url);

            if (! $response->successful() || ! File::exists($tempPath)) {
                File::delete($tempPath);

                return null;
            }

            return hash_file('sha256', $tempPath) ?: null;
        } catch (Throwable) {
            return null;
        } finally {
            File::delete($tempPath);
        }
    }

    public function hashLocalFile(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }
}
