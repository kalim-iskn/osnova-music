<?php

namespace App\Services\Genius;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeniusClient
{
    private string $baseUrl;

    private int $timeout;

    private int $cacheTtl;

    private int $songsPerPage;

    private int $albumsPerPage;

    private int $maxPages;

    private ?string $accessToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.genius.base_url', 'https://genius.com/api'), '/');
        $this->timeout = max(5, (int) config('services.genius.timeout', 20));
        $this->cacheTtl = max(60, (int) config('services.genius.cache_ttl', 86400));
        $this->songsPerPage = max(10, (int) config('services.genius.songs_per_page', 50));
        $this->albumsPerPage = max(10, (int) config('services.genius.albums_per_page', 50));
        $this->maxPages = max(1, (int) config('services.genius.max_pages', 100));
        $this->accessToken = config('services.genius.access_token');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchArtist(string $query): array
    {
        $payload = $this->get('search/artist', [
            'q' => $query,
        ], 'search-artist:' . md5($query));

        $sections = Arr::get($payload, 'response.sections', []);

        foreach ($sections as $section) {
            if (($section['type'] ?? null) !== 'artist') {
                continue;
            }

            return collect((array) ($section['hits'] ?? []))
                ->map(fn ($hit) => is_array($hit) ? ($hit['result'] ?? null) : null)
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function artist(int $geniusId): ?array
    {
        $payload = $this->get('artists/' . $geniusId, [], 'artist:' . $geniusId);

        return Arr::get($payload, 'response.artist');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchSongs(string $query): array
    {
        $payload = $this->get('search', [
            'q' => $query,
        ], 'search-song:' . md5($query), allowFailure: true);

        $hits = collect((array) Arr::get($payload, 'response.hits', []))
            ->filter(fn ($hit) => is_array($hit))
            ->values();

        if ($hits->isEmpty()) {
            $hits = collect((array) Arr::get($payload, 'response.sections', []))
                ->filter(fn ($section) => is_array($section) && ($section['type'] ?? null) === 'song')
                ->flatMap(fn (array $section) => (array) ($section['hits'] ?? []))
                ->filter(fn ($hit) => is_array($hit))
                ->values();
        }

        return $hits
            ->map(fn (array $hit) => is_array($hit['result'] ?? null) ? $hit['result'] : null)
            ->filter()
            ->unique(fn (array $song) => (int) ($song['id'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function song(int $geniusId): ?array
    {
        $payload = $this->get('songs/' . $geniusId, [], 'song:' . $geniusId);

        return Arr::get($payload, 'response.song');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function album(int $geniusId): ?array
    {
        $payload = $this->get('albums/' . $geniusId, [], 'album:' . $geniusId);

        return Arr::get($payload, 'response.album');
    }

    /**
     * @return array<int, int>
     */
    public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
    {
        return Cache::remember('album-track-numbers:' . $albumId, $this->cacheTtl, function () use ($albumId, $albumUrl): array {
            $albumPayload = $albumUrl ? null : $this->album($albumId);
            $albumUrl ??= is_array($albumPayload) ? (string) ($albumPayload['url'] ?? '') : '';

            if ($albumUrl === '') {
                return [];
            }

            $html = $this->requestHtml($albumUrl);

            if ($html === null || trim($html) === '') {
                return [];
            }

            return $this->extractTrackNumbersFromAlbumHtml($html);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allArtistSongs(int $artistId): array
    {
        $songs = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $payload = $this->get('artists/' . $artistId . '/songs', [
                'page' => $page,
                'per_page' => $this->songsPerPage,
                'include_features' => true,
            ], 'artist-songs:' . $artistId . ':' . $page);

            $items = collect((array) Arr::get($payload, 'response.songs', []))
                ->filter(fn ($song) => is_array($song))
                ->values()
                ->all();

            if ($items === []) {
                break;
            }

            array_push($songs, ...$items);

            if (empty(Arr::get($payload, 'response.next_page'))) {
                break;
            }
        }

        return collect($songs)
            ->unique(fn ($song) => (int) ($song['id'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allArtistAlbums(int $artistId): array
    {
        $albums = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $payload = $this->get('artists/' . $artistId . '/albums', [
                'page' => $page,
                'per_page' => $this->albumsPerPage,
            ], 'artist-albums:' . $artistId . ':' . $page, allowFailure: true);

            $items = collect((array) Arr::get($payload, 'response.albums', []))
                ->filter(fn ($album) => is_array($album))
                ->values()
                ->all();

            if ($items === []) {
                break;
            }

            array_push($albums, ...$items);

            if (empty(Arr::get($payload, 'response.next_page'))) {
                break;
            }
        }

        return collect($albums)
            ->unique(fn ($album) => (int) ($album['id'] ?? 0))
            ->values()
            ->all();
    }

    public function artistImageUrl(array $artist): ?string
    {
        return Arr::get($artist, 'user.avatar.medium.url')
            ?? Arr::get($artist, 'image_url')
            ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], ?string $cacheKey = null, bool $allowFailure = false): array
    {
        $cacheKey ??= 'genius:' . md5($path . ':' . json_encode($query));

        File::ensureDirectoryExists(storage_path('framework'));
        File::ensureDirectoryExists(storage_path('framework/cache'));
        File::ensureDirectoryExists(storage_path('framework/cache/data'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($path, $query, $allowFailure): array {
            $response = $this->request()
                ->get($this->baseUrl . '/' . ltrim($path, '/'), $query);

            if ($allowFailure && $response->status() === 404) {
                return [];
            }

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'Genius API вернул %d для %s',
                    $response->status(),
                    $path,
                ));
            }

            $payload = $response->json();

            return is_array($payload) ? $this->sanitizePayload($payload) : [];
        });
    }

    private function requestHtml(string $url): ?string
    {
        $response = Http::timeout($this->timeout)
            ->retry(2, 700, throw: false)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Upgrade-Insecure-Requests' => '1',
            ])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        return GeniusNameMatcher::forceUtf8((string) $response->body());
    }

    /**
     * @return array<int, int>
     */
    private function extractTrackNumbersFromAlbumHtml(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $map = [];

        $patterns = [
            '/"number"\s*:\s*"?(?<number>\d+)"?.{0,1800}?"song"\s*:\s*\{.{0,1800}?"id"\s*:\s*(?<id>\d+)/su',
            '/"song"\s*:\s*\{.{0,1800}?"id"\s*:\s*(?<id>\d+).{0,1800}?"number"\s*:\s*"?(?<number>\d+)"/su',
            '/"song_id"\s*:\s*(?<id>\d+).{0,600}?"(?:track_)?number"\s*:\s*"?(?<number>\d+)"/su',
            '/"id"\s*:\s*(?<id>\d+).{0,600}?"track_number"\s*:\s*"?(?<number>\d+)"/su',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $songId = (int) ($match['id'] ?? 0);
                $trackNumber = (int) ($match['number'] ?? 0);

                if ($songId <= 0 || $trackNumber <= 0 || isset($map[$songId])) {
                    continue;
                }

                $map[$songId] = $trackNumber;
            }

            if ($map !== []) {
                return $map;
            }
        }

        return $map;
    }

    private function sanitizePayload(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $itemKey => $item) {
                $value[$itemKey] = $this->sanitizePayload($item, is_string($itemKey) ? $itemKey : null);
            }

            return $value;
        }

        if (is_string($value)) {
            if ($key === 'description_preview') {
                return GeniusNameMatcher::cleanDescriptionPreview($value);
            }

            return GeniusNameMatcher::forceUtf8($value);
        }

        return $value;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 700, throw: false)
            ->withHeaders([
                'User-Agent' => 'WaveFlow Genius Sync/1.0',
                'Accept-Language' => 'ru,en;q=0.9',
            ]);

        if ($this->accessToken) {
            $request = $request->withToken($this->accessToken);
        }

        return $request;
    }
}
