<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackStreamController extends Controller
{
    public function __invoke(Request $request, Track $track): BinaryFileResponse|StreamedResponse
    {
        $source = trim((string) $track->audio_url);

        abort_if($source === '', 404);

        if ($this->isPublicStorageSource($source)) {
            return response()->file($this->resolvePublicStoragePath($source));
        }

        return $this->streamRemote($request, $source);
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

    private function streamRemote(Request $request, string $source): StreamedResponse
    {
        $origin = $this->originFromUrl($source);
        $range = $request->header('Range');

        $response = Http::withHeaders(array_filter([
            'Accept' => 'audio/mpeg,audio/*;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Origin' => $origin,
            'Referer' => $origin ? $origin.'/' : null,
            'Range' => $range,
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
        ]))
            ->withOptions([
                'allow_redirects' => true,
                'http_errors' => false,
                'stream' => true,
                'verify' => false,
            ])
            ->timeout(120)
            ->connectTimeout(20)
            ->get($source);

        abort_if($response->failed(), $response->status() ?: 502);

        $psrResponse = $response->toPsrResponse();
        $stream = $psrResponse->getBody();
        $headers = $this->buildProxyHeaders($psrResponse->getHeaders());

        return response()->stream(function () use ($stream): void {
            while (! $stream->eof()) {
                echo $stream->read(8192);
                flush();
            }
        }, $response->status(), $headers);
    }

    private function buildProxyHeaders(array $headers): array
    {
        $forwardedHeaders = [
            'content-type',
            'content-length',
            'content-range',
            'accept-ranges',
            'cache-control',
            'last-modified',
            'etag',
        ];

        $result = [];

        foreach ($forwardedHeaders as $header) {
            if (! isset($headers[$header][0])) {
                continue;
            }

            $normalizedHeader = Str::of($header)
                ->replace('-', ' ')
                ->title()
                ->replace(' ', '-')
                ->toString();

            $result[$normalizedHeader] = $headers[$header][0];
        }

        $result['X-Accel-Buffering'] = 'no';

        return $result;
    }

    private function originFromUrl(string $source): ?string
    {
        $scheme = parse_url($source, PHP_URL_SCHEME);
        $host = parse_url($source, PHP_URL_HOST);

        if (! $scheme || ! $host) {
            return null;
        }

        $port = parse_url($source, PHP_URL_PORT);

        return $port ? sprintf('%s://%s:%d', $scheme, $host, $port) : sprintf('%s://%s', $scheme, $host);
    }
}
