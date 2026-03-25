<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrackStreamController extends Controller
{
    public function __invoke(Track $track): BinaryFileResponse|RedirectResponse
    {
        $source = trim((string) $track->audio_url);

        abort_if($source === '', 404);

        if ($this->isPublicStorageSource($source)) {
            return response()->file($this->resolvePublicStoragePath($source), [
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        abort_unless(filter_var($source, FILTER_VALIDATE_URL), 404);

        return redirect()->away($source);
    }

    private function isPublicStorageSource(string $source): bool
    {
        if (Str::startsWith($source, ['/storage/', 'storage/'])) {
            return true;
        }

        if (! filter_var($source, FILTER_VALIDATE_URL)) {
            return false;
        }

        $path = parse_url($source, PHP_URL_PATH) ?: '';

        return Str::startsWith($path, '/storage/');
    }

    private function resolvePublicStoragePath(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH) ?: $source;
        $normalizedPath = Str::startsWith($path, '/storage/') ? $path : '/'.ltrim($path, '/');
        $relativePath = ltrim(Str::after($normalizedPath, '/storage/'), '/');
        $absolutePath = Storage::disk('public')->path($relativePath);

        abort_unless(is_file($absolutePath), 404);

        return $absolutePath;
    }
}
