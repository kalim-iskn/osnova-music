<?php

namespace App\Services\Genius;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeniusClient
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

    private const COOKIE_JAR_CACHE_TTL = 2592000;

    private string $baseUrl = 'https://genius.com/api';

    private int $timeout = 20;

    private int $songsPerPage = 50;

    private int $albumsPerPage = 50;

    private int $maxPages = 100;

    private ?string $accessToken = null;

    private CookieJar $cookieJar;

    private string $cacheVersion = '20260330-4';

    private int $retryTimes = 4;

    private int $retrySleepMs = 1500;

    private int $detailConcurrency = 12;

    private int $albumTrackConcurrency = 6;

    private int $detailTimeout = 10;

    private int $cacheTtl = 3600;

    private string $logChannel = 'genius_matching';

    /** @var array<string, int|string> */
    private array $logContext = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $runtimeCache = [];

    /** @var array<int, string> */
    private array $runtimeSearchCacheKeys = [];

    /**
     * @var array<string, string>
     */
    private array $runtimeFailures = [];

    /** @var array{requests:int,retries:int,duration_ms:int,groups:array<string,array{requests:int,retries:int,duration_ms:int,errors:int,statuses:array<string,int>}>} */
    private array $requestMetrics = [
        'requests' => 0,
        'retries' => 0,
        'duration_ms' => 0,
        'groups' => [],
    ];

    /**
     * @var array<string, array<int, int>>
     */
    private array $runtimeAlbumTrackNumbersCache = [];

    /** @var array<int, AlbumTracklistResult> */
    private array $albumTracklistResults = [];

    /** @var array<int, float|null> */
    private array $appleMusicDurations = [];

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.genius.base_url', 'https://genius.com/api'), '/');
        $this->timeout = max(5, (int) config('services.genius.timeout', 20));
        $this->songsPerPage = max(10, (int) config('services.genius.songs_per_page', 50));
        $this->albumsPerPage = max(10, (int) config('services.genius.albums_per_page', 50));
        $this->maxPages = max(1, (int) config('services.genius.max_pages', 100));
        $this->accessToken = config('services.genius.access_token');
        $this->cacheVersion = (string) config('services.genius.cache_version', '20260330-4');
        $this->cookieJar = $this->createCookieJar(trim((string) config('services.genius.cookie', '')));
        $this->retryTimes = max(1, (int) config('services.genius.retry_times', 4));
        $this->retrySleepMs = max(250, (int) config('services.genius.retry_sleep_ms', 1500));
        $this->detailConcurrency = max(1, min(16, (int) config('services.genius.detail_concurrency', 12)));
        $this->albumTrackConcurrency = max(1, min(8, (int) config('services.genius.album_track_concurrency', 6)));
        $this->detailTimeout = max(5, (int) config('services.genius.detail_timeout', 10));
        $this->cacheTtl = max(0, (int) config('services.genius.cache_ttl', 3600));
        $this->logChannel = (string) config('services.genius.log_channel', 'genius_matching');
    }

    public function resetMetrics(): void
    {
        $this->requestMetrics = ['requests' => 0, 'retries' => 0, 'duration_ms' => 0, 'groups' => []];
    }

    public function beginRun(): void
    {
        $this->runtimeCache = [];
        $this->runtimeSearchCacheKeys = [];
        $this->runtimeFailures = [];
        $this->runtimeAlbumTrackNumbersCache = [];
        $this->albumTracklistResults = [];
        $this->appleMusicDurations = [];
        $this->resetMetrics();
    }

    public function songAppleMusicDurationSeconds(int $songId): ?float
    {
        if ($songId <= 0) {
            return null;
        }
        if (array_key_exists($songId, $this->appleMusicDurations)) {
            return $this->appleMusicDurations[$songId];
        }
        $this->appleMusicDurations[$songId] = null;
        $parts = parse_url($this->baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        try {
            $html = $this->requestHtml($origin . '/songs/' . $songId . '/apple_music_player?app_config-is_react=1', 'songs/apple_music_player');
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($html) || $html === '') {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($dom->getElementsByTagName('apple-music-player') as $player) {
            if ($player->getAttribute('is_matched') !== 'true' || (int) $player->getAttribute('song_id') !== $songId) {
                continue;
            }
            $preview = json_decode($player->getAttribute('preview_track'), true);
            $tracks = json_decode($player->getAttribute('apple_music_tracks'), true);
            if (! is_array($preview) || ! is_array($tracks) || empty($preview['apple_id']) || ! is_numeric($preview['duration'] ?? null)) {
                continue;
            }
            $duration = (float) $preview['duration'];
            if (! is_finite($duration) || $duration <= 0 || $duration > 86400) {
                continue;
            }
            foreach ($tracks as $track) {
                if (is_array($track) && (string) ($track['apple_id'] ?? '') === (string) $preview['apple_id']
                    && is_numeric($track['duration'] ?? null) && abs((float) $track['duration'] - $duration) < 1) {
                    return $this->appleMusicDurations[$songId] = $duration;
                }
            }
        }
        return null;
    }

    /** @return array{requests:int,retries:int,duration_ms:int,groups:array<string,array{requests:int,retries:int,duration_ms:int,errors:int,statuses:array<string,int>}>} */
    public function metrics(): array
    {
        return $this->requestMetrics;
    }

    /** @param array<string, int|string> $context */
    public function setLogContext(array $context): void
    {
        $this->logContext = $context;
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

        return $this->songSearchResults($payload);
    }

    /** @return array<int, array<string, mixed>> */
    private function songSearchResults(array $payload): array
    {

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

    /** Keep only the song summaries used by matching in the cross-run cache. */
    private function compactSongSearchPayload(array $payload): array
    {
        return [
            'response' => [
                'hits' => array_map(
                    static fn (array $song): array => ['result' => $song],
                    $this->songSearchResults($payload),
                ),
            ],
        ];
    }

    /** Bound per-artist search-response memory; the service also caches merged summaries. */
    private function rememberRuntimeSearchPayload(string $key, array $payload): void
    {
        $this->runtimeSearchCacheKeys = array_values(array_filter(
            $this->runtimeSearchCacheKeys,
            static fn (string $cachedKey): bool => $cachedKey !== $key,
        ));
        $this->runtimeSearchCacheKeys[] = $key;

        $this->runtimeCache[$key] = $payload;

        while (count($this->runtimeSearchCacheKeys) > 256) {
            $oldestKey = array_shift($this->runtimeSearchCacheKeys);
            if (is_string($oldestKey)) {
                unset($this->runtimeCache[$oldestKey]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function song(int $geniusId): ?array
    {
        $payload = $this->get('songs/' . $geniusId, [], 'song:' . $geniusId, allowFailure: true);

        return Arr::get($payload, 'response.song');
    }

    /**
     * @param  int[]  $songIds
     * @return array<int, array<string, mixed>|null>
     */
    public function songDetails(array $songIds): array
    {
        $startedAt = hrtime(true);
        $songs = [];
        $missing = [];
        $cacheHitCount = 0;
        $failedCacheHitCount = 0;
        $failedCount = 0;

        if ((new \ReflectionMethod($this, 'song'))->getDeclaringClass()->getName() !== self::class) {
            foreach (array_unique(array_map('intval', $songIds)) as $songId) {
                if ($songId > 0) {
                    $songs[$songId] = $this->song($songId);
                }
            }

            return $songs;
        }

        foreach (array_unique(array_map('intval', $songIds)) as $songId) {
            if ($songId <= 0) {
                continue;
            }

            $cacheKey = $this->cacheKey('song:' . $songId);

            if (array_key_exists($cacheKey, $this->runtimeFailures)) {
                $songs[$songId] = null;
                $failedCacheHitCount++;
                $failedCount++;

                continue;
            }

            if (array_key_exists($cacheKey, $this->runtimeCache)) {
                $songs[$songId] = Arr::get($this->runtimeCache[$cacheKey], 'response.song');
                $cacheHitCount++;

                continue;
            }

            $missing[$songId] = $cacheKey;
        }

        foreach (array_chunk(array_keys($missing), 48) as $songIdChunk) {
            $chunkStartedAt = hrtime(true);
            $attemptCounts = [];
            try {
                $responses = Http::pool(function (Pool $pool) use ($songIdChunk, &$attemptCounts): void {
                    foreach ($songIdChunk as $songId) {
                        $url = $this->baseUrl . '/songs/' . $songId;
                        $attemptCounts[$songId] = 0;
                        $request = $pool->as((string) $songId)
                            ->acceptJson()
                            ->timeout($this->detailTimeout)
                            ->retry(
                                $this->retryTimes,
                                $this->retrySleepMs,
                                fn (\Throwable $exception): bool => $this->shouldRetryRequest($exception),
                                throw: false,
                            )
                            ->withHeaders($this->geniusHeaders())
                            ->withOptions(['cookies' => $this->cookieJar])
                            ->withRequestMiddleware(function ($request) use (&$attemptCounts, $songId) {
                                $attemptCounts[$songId]++;

                                return $request;
                            });

                        if ($this->accessToken) {
                            $request = $request->withToken($this->accessToken);
                        }

                        $request->get($url);
                    }
                }, $this->detailConcurrency);
            } finally {
                $this->persistCookieJar();
            }

            $batchDurationMs = (int) round((hrtime(true) - $chunkStartedAt) / 1_000_000);
            $this->requestMetrics['duration_ms'] += $batchDurationMs;
            $this->requestMetrics['groups']['song_detail'] ??= [
                'requests' => 0,
                'retries' => 0,
                'duration_ms' => 0,
                'errors' => 0,
                'statuses' => [],
            ];
            $this->requestMetrics['groups']['song_detail']['duration_ms'] =
                ($this->requestMetrics['groups']['song_detail']['duration_ms'] ?? 0) + $batchDurationMs;

            foreach ($songIdChunk as $songId) {
                $cacheKey = $missing[$songId];
                $response = $responses[(string) $songId] ?? null;

                if ($response instanceof \Throwable) {
                    $this->runtimeFailures[$cacheKey] = $response->getMessage();
                    $this->recordRequestMetric('songs/' . $songId, 'exception', 0, max(0, ($attemptCounts[$songId] ?? 1) - 1));
                    $this->httpLog('warning', 'Genius song detail request failed.', [
                        'path' => 'songs/' . $songId,
                        'exception' => $response->getMessage(),
                    ]);
                    $songs[$songId] = null;
                    $failedCount++;

                    continue;
                }

                if (! $response instanceof Response) {
                    $this->runtimeFailures[$cacheKey] = 'No HTTP response was returned.';
                    $this->recordRequestMetric('songs/' . $songId, 'exception', 0, max(0, ($attemptCounts[$songId] ?? 1) - 1));
                    $songs[$songId] = null;
                    $failedCount++;

                    continue;
                }

                if ($response->status() === 404) {
                    $this->recordRequestMetric('songs/' . $songId, 404, 0, max(0, ($attemptCounts[$songId] ?? 1) - 1));
                    $payload = [];
                } elseif (! $response->successful()) {
                    $this->recordRequestMetric('songs/' . $songId, $response->status(), 0, max(0, ($attemptCounts[$songId] ?? 1) - 1));
                    $this->runtimeFailures[$cacheKey] = sprintf(
                        'Genius API вернул %d для songs/%d',
                        $response->status(),
                        $songId,
                    );
                    $this->httpLog('warning', 'Genius API request failed.', [
                        'path' => 'songs/' . $songId,
                        'query' => [],
                        'status' => $response->status(),
                        'allow_failure' => true,
                        'body_preview' => mb_substr((string) $response->body(), 0, 500),
                    ]);
                    $songs[$songId] = null;
                    $failedCount++;

                    continue;
                } else {
                    $this->recordRequestMetric('songs/' . $songId, $response->status(), 0, max(0, ($attemptCounts[$songId] ?? 1) - 1));
                    $responsePayload = $response->json();
                    $payload = is_array($responsePayload) ? $this->sanitizePayload($responsePayload) : [];
                }

                $this->runtimeCache[$cacheKey] = $payload;
                $songs[$songId] = Arr::get($payload, 'response.song');
            }
        }

        $this->httpLog('info', 'Genius song details batch completed.', [
            'requested_count' => count(array_unique(array_filter(array_map('intval', $songIds), fn (int $songId): bool => $songId > 0))),
            'cached_count' => $cacheHitCount,
            'failed_cached_count' => $failedCacheHitCount,
            'network_count' => count($missing),
            'failed_count' => $failedCount,
            'concurrency' => $this->detailConcurrency,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $songs;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function album(int $geniusId): ?array
    {
        $payload = $this->get('albums/' . $geniusId, [], 'album:' . $geniusId, allowFailure: true);

        return Arr::get($payload, 'response.album');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function albumTracks(int $albumId): array
    {
        return $this->albumTracklistResult($albumId)->tracks;
    }

    public function albumTracklistResult(int $albumId): AlbumTracklistResult
    {
        if (isset($this->albumTracklistResults[$albumId])) {
            return $this->albumTracklistResults[$albumId];
        }

        if ((new \ReflectionMethod($this, 'albumTracks'))->getDeclaringClass()->getName() !== self::class) {
            try {
                $tracks = $this->albumTracks($albumId);
                if ($tracks === []) {
                    $legacyResult = $this->albumTracklistFromLegacyNumberOverride($albumId);
                    if ($legacyResult instanceof AlbumTracklistResult) {
                        return $this->albumTracklistResults[$albumId] = $legacyResult;
                    }
                }

                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult($tracks, true, null, 1);
            } catch (\Throwable $exception) {
                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult([], false, $exception->getMessage(), 0);
            }
        }

        // Keep legacy GeniusClient test doubles that override only the old
        // albumTrackNumbers() seam usable, without adding this fallback to the
        // real client's request path.
        $legacyResult = $this->albumTracklistFromLegacyNumberOverride($albumId);
        if ($legacyResult instanceof AlbumTracklistResult) {
            return $this->albumTracklistResults[$albumId] = $legacyResult;
        }

        $tracks = [];
        $pagesFetched = 0;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $payload = $this->get('albums/' . $albumId . '/tracks', [
                    'page' => $page,
                    'per_page' => $this->songsPerPage,
                ], 'album-tracks:' . $albumId . ':' . $page . ':per:' . $this->songsPerPage, allowFailure: true);
            } catch (\Throwable $exception) {
                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
                    $this->uniqueAlbumTracks($tracks), false, $exception->getMessage(), $pagesFetched,
                );
            }

            if (! is_array(Arr::get($payload, 'response.tracks'))) {
                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
                    $this->uniqueAlbumTracks($tracks), false, 'Genius returned an invalid album tracklist payload.', $pagesFetched,
                );
            }

            $items = collect((array) Arr::get($payload, 'response.tracks', []))
                ->filter(fn ($track) => is_array($track))
                ->values()
                ->all();

            $itemsPayload = Arr::get($payload, 'response.tracks', []);
            if (! $this->albumTrackItemsAreValid($itemsPayload)) {
                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
                    $this->uniqueAlbumTracks($tracks), false, 'Genius returned malformed items in the album tracklist.', $pagesFetched,
                );
            }

            $pagesFetched++;

            if ($items === []) {
                if (! empty(Arr::get($payload, 'response.next_page'))) {
                    return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
                        $this->uniqueAlbumTracks($tracks), false, 'Genius returned an empty page with a next page advertised.', $pagesFetched,
                    );
                }

                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult([], true, null, $pagesFetched);
            }

            array_push($tracks, ...$items);

            $nextPage = Arr::get($payload, 'response.next_page');

            if (empty($nextPage)) {
                return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
                    $this->uniqueAlbumTracks($tracks), true, null, $pagesFetched,
                );
            }
        }

        return $this->albumTracklistResults[$albumId] = new AlbumTracklistResult(
            $this->uniqueAlbumTracks($tracks), false, 'Genius album tracklist exceeded the configured page limit.', $pagesFetched,
        );
    }

    private function albumTracklistFromLegacyNumberOverride(int $albumId): ?AlbumTracklistResult
    {
        if ((new \ReflectionMethod($this, 'albumTrackNumbers'))->getDeclaringClass()->getName() === self::class) {
            return null;
        }

        try {
            $numbers = $this->albumTrackNumbers($albumId);
            $tracks = [];

            foreach ($numbers as $songId => $number) {
                $songId = (int) $songId;
                if ($songId <= 0) {
                    continue;
                }

                $song = null;
                if ((new \ReflectionMethod($this, 'song'))->getDeclaringClass()->getName() !== self::class) {
                    $song = $this->song($songId);
                }

                if (! is_array($song)
                    && (new \ReflectionMethod($this, 'allArtistSongs'))->getDeclaringClass()->getName() !== self::class) {
                    $song = collect($this->allArtistSongs(0))
                        ->first(fn ($candidate) => is_array($candidate) && (int) ($candidate['id'] ?? 0) === $songId);
                }

                if (! is_array($song)) {
                    return new AlbumTracklistResult(
                        [],
                        false,
                        'A legacy album-track test double returned a song without a summary.',
                        0,
                    );
                }

                $tracks[] = [
                    'number' => (int) $number,
                    'song' => $song,
                ];
            }

            return new AlbumTracklistResult($tracks, true, null, 1);
        } catch (\Throwable $exception) {
            return new AlbumTracklistResult([], false, $exception->getMessage(), 0);
        }
    }

    /** @param array<int, array<string, mixed>> $tracks
     *  @return array<int, array<string, mixed>>
     */
    private function uniqueAlbumTracks(array $tracks): array
    {
        return collect($tracks)
            ->unique(function (array $track): int {
                $song = is_array($track['song'] ?? null) ? $track['song'] : $track;

                return (int) ($song['id'] ?? 0);
            })
            ->values()
            ->all();
    }

    private function albumTrackItemsAreValid(mixed $items): bool
    {
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            $song = is_array($item['song'] ?? null) ? $item['song'] : $item;
            if ((int) ($song['id'] ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch album tracklists in bounded concurrent page batches.
     *
     * @param  int[]  $albumIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function albumTracksMany(array $albumIds): array
    {
        $albumIds = collect($albumIds)
            ->map(fn ($albumId): int => (int) $albumId)
            ->filter(fn (int $albumId): bool => $albumId > 0)
            ->unique()
            ->values()
            ->all();

        if ($albumIds === []) {
            return [];
        }

        // Preserve injected/mock client behavior in tests and integrations that
        // specialize the single-album API method.
        if ((new \ReflectionMethod($this, 'albumTracks'))->getDeclaringClass()->getName() !== self::class
            || (new \ReflectionMethod($this, 'albumTrackNumbers'))->getDeclaringClass()->getName() !== self::class) {
            $result = [];

            foreach ($albumIds as $albumId) {
                $tracklist = $this->albumTracklistResult($albumId);
                $result[$albumId] = $tracklist->tracks;
            }

            return $result;
        }

        $startedAt = hrtime(true);
        $tracksByAlbum = array_fill_keys($albumIds, []);
        $completeByAlbum = array_fill_keys($albumIds, true);
        $pagesByAlbum = array_fill_keys($albumIds, 0);
        $errorsByAlbum = array_fill_keys($albumIds, null);
        $activeAlbumIds = $albumIds;

        for ($page = 1; $page <= $this->maxPages && $activeAlbumIds !== []; $page++) {
            $payloads = [];
            $missing = [];

            foreach ($activeAlbumIds as $albumId) {
                $key = $this->cacheKey('album-tracks:' . $albumId . ':' . $page . ':per:' . $this->songsPerPage);

                if (array_key_exists($key, $this->runtimeCache)) {
                    $payloads[$albumId] = $this->runtimeCache[$key];
                } elseif (! array_key_exists($key, $this->runtimeFailures)) {
                    $missing[$albumId] = $key;
                }
            }

            foreach (array_chunk(array_keys($missing), $this->albumTrackConcurrency) as $albumIdChunk) {
                $chunkStartedAt = hrtime(true);
                $attemptCounts = [];

                try {
                    $responses = Http::pool(function (Pool $pool) use ($albumIdChunk, $page, &$attemptCounts): void {
                        foreach ($albumIdChunk as $albumId) {
                            $key = $albumId . ':' . $page;
                            $attemptCounts[$key] = 0;
                            $request = $pool->as($key)
                                ->acceptJson()
                                ->timeout($this->timeout)
                                ->retry(
                                    $this->retryTimes,
                                    $this->retrySleepMs,
                                    fn (\Throwable $exception): bool => $this->shouldRetryRequest($exception),
                                    throw: false,
                                )
                                ->withHeaders($this->geniusHeaders())
                                ->withOptions(['cookies' => $this->cookieJar])
                                ->withRequestMiddleware(function ($request) use (&$attemptCounts, $key) {
                                    $attemptCounts[$key]++;

                                    return $request;
                                });

                            if ($this->accessToken) {
                                $request = $request->withToken($this->accessToken);
                            }

                            $request->get($this->baseUrl . '/albums/' . $albumId . '/tracks', [
                                'page' => $page,
                                'per_page' => $this->songsPerPage,
                            ]);
                        }
                    }, $this->albumTrackConcurrency);
                } finally {
                    $this->persistCookieJar();
                }

                $batchDurationMs = (int) round((hrtime(true) - $chunkStartedAt) / 1_000_000);
                $this->requestMetrics['duration_ms'] += $batchDurationMs;
                $this->requestMetrics['groups']['album'] ??= [
                    'requests' => 0,
                    'retries' => 0,
                    'duration_ms' => 0,
                    'errors' => 0,
                    'statuses' => [],
                ];
                $this->requestMetrics['groups']['album']['duration_ms'] += $batchDurationMs;

                foreach ($albumIdChunk as $albumId) {
                    $requestKey = $albumId . ':' . $page;
                    $cacheKey = $missing[$albumId];
                    $response = $responses[$requestKey] ?? null;
                    $retries = max(0, ($attemptCounts[$requestKey] ?? 1) - 1);
                    $path = 'albums/' . $albumId . '/tracks';

                    if ($response instanceof \Throwable || ! $response instanceof Response) {
                        $message = $response instanceof \Throwable ? $response->getMessage() : 'No HTTP response was returned.';
                        $this->runtimeFailures[$cacheKey] = $message;
                        $completeByAlbum[$albumId] = false;
                        $errorsByAlbum[$albumId] = $message;
                        $this->recordRequestMetric($path, 'exception', 0, $retries);
                        $this->httpLog('warning', 'Genius album tracks batch request failed.', [
                            'path' => $path,
                            'query' => ['page' => $page, 'per_page' => $this->songsPerPage],
                            'exception' => $message,
                            'retries' => $retries,
                        ]);

                        continue;
                    }

                    if ($response->status() === 404) {
                        $this->recordRequestMetric($path, 404, 0, $retries);
                        $completeByAlbum[$albumId] = false;
                        $errorsByAlbum[$albumId] = 'Genius returned 404 for album tracklist.';
                        continue;
                    }

                    if (! $response->successful()) {
                        $message = sprintf('Genius API вернул %d для %s', $response->status(), $path);
                        $this->runtimeFailures[$cacheKey] = $message;
                        $completeByAlbum[$albumId] = false;
                        $errorsByAlbum[$albumId] = $message;
                        $this->recordRequestMetric($path, $response->status(), 0, $retries);
                        $this->httpLog('warning', 'Genius album tracks batch request failed.', [
                            'path' => $path,
                            'query' => ['page' => $page, 'per_page' => $this->songsPerPage],
                            'status' => $response->status(),
                            'allow_failure' => true,
                            'retries' => $retries,
                            'body_preview' => mb_substr((string) $response->body(), 0, 500),
                        ]);

                        continue;
                    }

                    $this->recordRequestMetric($path, $response->status(), 0, $retries);
                    $responsePayload = $response->json();
                    if (! is_array($responsePayload) || ! is_array(Arr::get($responsePayload, 'response.tracks'))) {
                        $completeByAlbum[$albumId] = false;
                        $errorsByAlbum[$albumId] = 'Genius returned an invalid album tracklist payload.';
                        $this->runtimeFailures[$cacheKey] = $errorsByAlbum[$albumId];
                        continue;
                    }
                    $payloads[$albumId] = $this->runtimeCache[$cacheKey] = $this->sanitizePayload($responsePayload);
                    $this->httpLog('info', 'Genius album tracks batch request completed.', [
                        'path' => $path,
                        'page' => $page,
                        'status' => $response->status(),
                        'retries' => $retries,
                    ]);
                }
            }

            $nextActiveAlbumIds = [];

            foreach ($activeAlbumIds as $albumId) {
                if (! array_key_exists($albumId, $payloads)) {
                    $completeByAlbum[$albumId] = false;
                    $errorsByAlbum[$albumId] ??= 'Genius album tracklist page was not received.';
                    continue;
                }

                $payload = $payloads[$albumId];
                $itemsPayload = Arr::get($payload, 'response.tracks');
                if (! is_array($itemsPayload)) {
                    $completeByAlbum[$albumId] = false;
                    $errorsByAlbum[$albumId] ??= 'Genius returned an invalid album tracklist payload.';
                    continue;
                }
                $itemsPayload = Arr::get($payload, 'response.tracks', []);
                $items = collect((array) $itemsPayload)
                    ->filter(fn ($track) => is_array($track))
                    ->values()
                    ->all();

                if (! $this->albumTrackItemsAreValid($itemsPayload)) {
                    $completeByAlbum[$albumId] = false;
                    $errorsByAlbum[$albumId] ??= 'Genius returned malformed items in the album tracklist.';
                    continue;
                }

                $pagesByAlbum[$albumId]++;

                if ($items === []) {
                    if (! empty(Arr::get($payload, 'response.next_page'))) {
                        $completeByAlbum[$albumId] = false;
                        $errorsByAlbum[$albumId] ??= 'Genius returned an empty page with a next page advertised.';
                    }
                    continue;
                }

                array_push($tracksByAlbum[$albumId], ...$items);

                if (! empty(Arr::get($payload, 'response.next_page'))) {
                    $nextActiveAlbumIds[] = $albumId;
                }
            }

            $activeAlbumIds = $nextActiveAlbumIds;
        }

        $result = [];

        foreach ($activeAlbumIds as $albumId) {
            $completeByAlbum[$albumId] = false;
            $errorsByAlbum[$albumId] ??= 'Genius album tracklist exceeded the configured page limit.';
        }

        foreach ($tracksByAlbum as $albumId => $tracks) {
            $result[$albumId] = $this->uniqueAlbumTracks($tracks);
            $this->albumTracklistResults[(int) $albumId] = new AlbumTracklistResult(
                $result[$albumId],
                (bool) ($completeByAlbum[$albumId] ?? false),
                $errorsByAlbum[$albumId] ?? null,
                (int) ($pagesByAlbum[$albumId] ?? 0),
            );
        }

        $this->httpLog('info', 'Genius album tracks batch completed.', [
            'album_count' => count($albumIds),
            'track_count' => array_sum(array_map('count', $result)),
            'concurrency' => $this->albumTrackConcurrency,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
    {
        $runtimeCacheKey = $albumId . '|' . (string) ($albumUrl ?? '');

        if (array_key_exists($runtimeCacheKey, $this->runtimeAlbumTrackNumbersCache)) {
            return $this->runtimeAlbumTrackNumbersCache[$runtimeCacheKey];
        }

        $trackNumbers = (function () use ($albumId, $albumUrl): array {
            $apiTracks = $this->albumTracks($albumId);
            $apiMap = [];

            foreach ($apiTracks as $track) {
                if (! is_array($track)) {
                    continue;
                }

                $song = is_array($track['song'] ?? null) ? $track['song'] : $track;
                $songId = (int) ($song['id'] ?? 0);
                $trackNumber = (int) ($track['number'] ?? $track['track_number'] ?? $song['track_number'] ?? $song['number'] ?? 0);

                if ($songId <= 0 || $trackNumber <= 0 || isset($apiMap[$songId])) {
                    continue;
                }

                $apiMap[$songId] = $trackNumber;
            }

            if ($apiMap !== []) {
                return $apiMap;
            }

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
        })();

        return $this->runtimeAlbumTrackNumbersCache[$runtimeCacheKey] = $trackNumbers;
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
            ], 'artist-songs:' . $artistId . ':' . $page . ':per:' . $this->songsPerPage . ':features:1', allowFailure: true);

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
            ], 'artist-albums:' . $artistId . ':' . $page . ':per:' . $this->albumsPerPage, allowFailure: true);

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

    private function httpLog(string $level, string $message, array $context = []): void
    {
        Log::channel($this->logChannel)->log($level, $message, array_merge($this->logContext, $context));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], ?string $cacheKey = null, bool $allowFailure = false): array
    {
        $cacheKey = $this->cacheKey($cacheKey ?? md5($path . ':' . json_encode($query)));

        if (array_key_exists($cacheKey, $this->runtimeCache)) {
            return $this->runtimeCache[$cacheKey];
        }

        if (array_key_exists($cacheKey, $this->runtimeFailures)) {
            throw new RuntimeException($this->runtimeFailures[$cacheKey]);
        }

        if ($path === 'search' && $this->cacheTtl > 0 && Cache::has($cacheKey)) {
            $cachedPayload = Cache::get($cacheKey);
            if (is_array($cachedPayload)) {
                $this->rememberRuntimeSearchPayload($cacheKey, $cachedPayload);

                return $cachedPayload;
            }
        }

        $fetch = function () use ($path, $query, $allowFailure): array {
            $startedAt = hrtime(true);
            $attemptCount = 0;
            $url = $this->baseUrl . '/' . ltrim($path, '/');
            try {
                $response = $this->request(function () use (&$attemptCount): void {
                    $attemptCount++;
                })->get($url, $query);
            } catch (\Throwable $exception) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                $retries = max(0, $attemptCount - 1);
                $this->recordRequestMetric($path, 'exception', $durationMs, $retries);
                $this->httpLog('warning', 'Genius API request threw an exception.', [
                    'path' => $path,
                    'query' => $query,
                    'exception' => $exception->getMessage(),
                    'duration_ms' => $durationMs,
                    'retries' => $retries,
                ]);

                throw $exception;
            } finally {
                $this->persistCookieJar();
            }

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $retries = max(0, $attemptCount - 1);
            $this->recordRequestMetric($path, $response->status(), $durationMs, $retries);

            if ($allowFailure && $response->status() === 404) {
                $this->httpLog('info', 'Genius API request completed.', [
                    'path' => $path,
                    'status' => 404,
                    'duration_ms' => $durationMs,
                    'retries' => $retries,
                ]);

                return [];
            }

            if (! $response->successful()) {
                $this->httpLog('warning', 'Genius API request failed.', [
                    'path' => $path,
                    'query' => $query,
                    'status' => $response->status(),
                    'allow_failure' => $allowFailure,
                    'duration_ms' => $durationMs,
                    'retries' => $retries,
                    'body_preview' => mb_substr((string) $response->body(), 0, 500),
                ]);

                throw new RuntimeException(sprintf(
                    'Genius API вернул %d для %s',
                    $response->status(),
                    $path,
                ));
            }

            $this->httpLog('info', 'Genius API request completed.', [
                'path' => $path,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'retries' => $retries,
            ]);

            $payload = $response->json();

            return is_array($payload) ? $this->sanitizePayload($payload) : [];
        };

        try {
            $payload = $fetch();

            if ($path === 'search') {
                $compactPayload = $this->compactSongSearchPayload($payload);
                $this->rememberRuntimeSearchPayload($cacheKey, $compactPayload);
                if ($this->cacheTtl > 0 && $payload !== []) {
                    Cache::put($cacheKey, $compactPayload, $this->cacheTtl);
                }

                return $payload;
            }

            return $this->runtimeCache[$cacheKey] = $payload;
        } catch (\Throwable $exception) {
            $this->runtimeFailures[$cacheKey] = $exception->getMessage();

            throw $exception;
        }
    }

    private function requestHtml(string $url, string $metricPath = 'albums/html'): ?string
    {
        $startedAt = hrtime(true);
        $attemptCount = 0;
        try {
            $response = Http::timeout($this->timeout)
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMs,
                    fn (\Throwable $exception): bool => $this->shouldRetryRequest($exception),
                    throw: false,
                )
                ->withHeaders($this->geniusHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                    'Upgrade-Insecure-Requests' => '1',
                ]))
                ->withOptions(['cookies' => $this->cookieJar])
                ->withRequestMiddleware(function ($request) use (&$attemptCount) {
                    $attemptCount++;

                    return $request;
                })
                ->get($url);
        } catch (\Throwable $exception) {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $retries = max(0, $attemptCount - 1);
            $this->recordRequestMetric($metricPath, 'exception', $durationMs, $retries);
            $this->httpLog('warning', 'Genius HTML request threw an exception.', [
                'url' => $url,
                'exception' => $exception->getMessage(),
                'duration_ms' => $durationMs,
                'retries' => $retries,
            ]);

            return null;
        } finally {
            $this->persistCookieJar();
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $retries = max(0, $attemptCount - 1);
        $this->recordRequestMetric($metricPath, $response->status(), $durationMs, $retries);

        if (! $response->successful()) {
            $this->httpLog('warning', 'Genius HTML request failed.', [
                'url' => $url,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'retries' => $retries,
                'body_preview' => mb_substr((string) $response->body(), 0, 500),
            ]);

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

    private function sanitizePayload(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($depth >= 8) {
            if (is_string($value)) {
                return mb_substr(GeniusNameMatcher::forceUtf8($value), 0, 2000);
            }

            return is_array($value) ? [] : $value;
        }

        if (is_array($value)) {
            if ($key !== null && in_array($key, [
                'description',
                'description_annotation',
                'description_dom',
                'children',
                'tracking_paths',
                'body',
            ], true)) {
                return [];
            }

            if (count($value) > 250 && ! in_array($key, ['songs', 'albums', 'tracks', 'hits', 'sections'], true)) {
                $value = array_slice($value, 0, 120, true);
            }

            foreach ($value as $itemKey => $item) {
                $value[$itemKey] = $this->sanitizePayload($item, is_string($itemKey) ? $itemKey : null, $depth + 1);
            }

            return $value;
        }

        if (is_string($value)) {
            if ($key === 'description_preview') {
                return GeniusNameMatcher::cleanDescriptionPreview($value);
            }

            $value = GeniusNameMatcher::forceUtf8($value);

            if (mb_strlen($value) > 4000) {
                return mb_substr($value, 0, 4000);
            }

            return $value;
        }

        return $value;
    }

    private function cacheKey(string $key): string
    {
        return 'genius:' . $this->cacheVersion . ':' . $key;
    }

    private function recordRequestMetric(string $path, int|string $status, int $durationMs = 0, int $retries = 0): void
    {
        $group = match (true) {
            str_starts_with($path, 'search/artist') => 'artist_search',
            $path === 'search' => 'song_search',
            str_starts_with($path, 'songs/') => 'song_detail',
            str_starts_with($path, 'albums/') => 'album',
            str_starts_with($path, 'artists/') => 'artist_catalog',
            default => 'other',
        };
        $statusKey = (string) $status;
        $this->requestMetrics['requests']++;
        $this->requestMetrics['retries'] += $retries;
        $this->requestMetrics['duration_ms'] += $durationMs;
        $this->requestMetrics['groups'][$group] ??= [
            'requests' => 0,
            'retries' => 0,
            'duration_ms' => 0,
            'errors' => 0,
            'statuses' => [],
        ];
        $this->requestMetrics['groups'][$group]['requests']++;
        $this->requestMetrics['groups'][$group]['retries'] += $retries;
        $this->requestMetrics['groups'][$group]['duration_ms'] += $durationMs;
        $this->requestMetrics['groups'][$group]['statuses'][$statusKey] =
            ($this->requestMetrics['groups'][$group]['statuses'][$statusKey] ?? 0) + 1;

        if ($status === 'exception' || (is_int($status) && $status >= 400)) {
            $this->requestMetrics['groups'][$group]['errors']++;
        }
    }

    private function request(?callable $onAttempt = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                fn (\Throwable $exception): bool => $this->shouldRetryRequest($exception),
                throw: false,
            )
            ->withHeaders($this->geniusHeaders())
            ->withOptions(['cookies' => $this->cookieJar]);

        if ($onAttempt !== null) {
            $request = $request->withRequestMiddleware(function ($pendingRequest) use ($onAttempt) {
                $onAttempt();

                return $pendingRequest;
            });
        }

        if ($this->accessToken) {
            $request = $request->withToken($this->accessToken);
        }

        return $request;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function geniusHeaders(array $headers = []): array
    {
        return array_merge([
            'User-Agent' => self::USER_AGENT,
            'Accept-Language' => 'ru,en;q=0.9',
        ], $headers);
    }

    private function createCookieJar(string $seedCookie): CookieJar
    {
        File::ensureDirectoryExists(storage_path('framework/cache/data'));
        $cookieCacheKey = $this->cacheKey('cookie-jar');
        $seedFingerprintKey = $this->cacheKey('cookie-seed-fingerprint');
        $cachedCookies = Cache::get($cookieCacheKey, []);
        $cookieJar = new CookieJar(false, is_array($cachedCookies) ? $cachedCookies : []);
        $seedCookie = preg_replace('/^\\s*Cookie:\\s*/i', '', $seedCookie) ?? $seedCookie;
        $seedFingerprint = hash('sha256', $seedCookie);
        $previousSeedFingerprint = Cache::get($seedFingerprintKey);
        $shouldApplySeed = $seedCookie !== ''
            && (count($cookieJar) === 0
                || ! is_string($previousSeedFingerprint)
                || ! hash_equals($previousSeedFingerprint, $seedFingerprint));

        if (! $shouldApplySeed) {
            return $cookieJar;
        }

        foreach (explode(';', $seedCookie) as $cookiePair) {
            $cookiePair = trim($cookiePair);

            if ($cookiePair === '' || ! str_contains($cookiePair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $cookiePair, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '' || $value === '' || str_contains($value, '...')) {
                continue;
            }

            $cookieJar->clear('genius.com', '/', $name);
            $cookieJar->setCookie(new SetCookie([
                'Domain' => 'genius.com',
                'Name' => $name,
                'Value' => $value,
                'Path' => '/',
                'Secure' => true,
                'Discard' => true,
            ]));
        }

        Cache::put($seedFingerprintKey, $seedFingerprint, self::COOKIE_JAR_CACHE_TTL);

        return $cookieJar;
    }

    private function persistCookieJar(): void
    {
        Cache::put($this->cacheKey('cookie-jar'), $this->cookieJar->toArray(), self::COOKIE_JAR_CACHE_TTL);
    }

    private function shouldRetryRequest(\Throwable $exception): bool
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $exception->response->serverError()
                || in_array($status, [403, 408, 425, 429], true);
        }

        return true;
    }
}
