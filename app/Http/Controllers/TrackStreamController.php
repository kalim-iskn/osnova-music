<?php

namespace App\Http\Controllers;

use App\Models\Track;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TrackStreamController extends Controller
{
    public function __invoke(Track $track): BinaryFileResponse|StreamedResponse|RedirectResponse
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

        try {
            $client = new Client([
                'timeout' => 90,
                'verify' => false,
                'allow_redirects' => true,
                'http_errors' => false,
                'stream' => true,
                'headers' => $this->requestHeadersFor($source),
            ]);

            $upstream = $client->request('GET', $source);

            if ($upstream->getStatusCode() >= 400) {
                return redirect()->away($source);
            }

            return response()->stream(function () use ($upstream): void {
                $body = $upstream->getBody();

                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, array_filter([
                'Content-Type' => $upstream->getHeaderLine('Content-Type') ?: 'audio/mpeg',
                'Content-Length' => $upstream->getHeaderLine('Content-Length') ?: null,
                'Accept-Ranges' => $upstream->getHeaderLine('Accept-Ranges') ?: 'bytes',
                'Cache-Control' => 'public, max-age=1800',
            ]));
        } catch (Throwable) {
            return redirect()->away($source);
        }
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

    private function requestHeadersFor(string $sourceUrl): array
    {
        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        $origin = $scheme && $host ? $scheme.'://'.$host : null;

        return array_filter([
            'Accept' => 'audio/mpeg,audio/*;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Origin' => $origin,
            'Referer' => $origin ? $origin.'/' : null,
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
        ]);
    }
}
