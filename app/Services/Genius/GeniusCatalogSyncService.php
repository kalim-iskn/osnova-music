<?php

namespace App\Services\Genius;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GeniusCatalogSyncService
{
    private bool $debugMatchingEnabled = false;

    /**
     * @var string[]|null
     */
    private ?array $trackColumns = null;

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $songDetails = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $searchSongSummaryCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $albumTrackSongCache = [];

    /**
     * @var array<int, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Album>>
     */
    private array $localArtistAlbums = [];

    /**
     * @var array<string, int>
     */
    private array $albumPageAlbumContext = [];

    private ?Artist $currentSyncArtist = null;

    /**
     * @var string[]
     */
    private array $currentPageArtistReferenceNames = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $currentSongExactTitleIndex = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $currentSongLooseTitleIndex = [];

    /**
     * @var array<int, array<string, bool>>
     */
    private array $artistSongTitleCatalogCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $preferredAlbumBySongId = [];

    /**
     * @var array<int, array<string, array<int, array<string, mixed>>>>
     */
    private array $preferredAlbumCandidatesByTitle = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $currentArtistAlbumSummaries = [];

    private int $currentArtistGeniusId = 0;

    public function __construct(
        private readonly GeniusClient $geniusClient,
    ) {
    }


    public function setNeedDebug(bool $debugMatchingEnabled): void
    {
        $this->debugMatchingEnabled = $debugMatchingEnabled;
    }

    private function debugMatchingEnabled(): bool
    {
        return $this->debugMatchingEnabled || (bool) config('services.genius.debug_matching', false);
    }

    private function matchingLog(string $level, string $message, array $context = []): void
    {
        if ($level === 'debug' && ! $this->debugMatchingEnabled()) {
            return;
        }

        Log::channel((string) config('services.genius.log_channel', 'genius_matching'))
            ->log($level, $message, $context);
    }

    /**
     * @param  ParsedTrack[]  $tracks
     * @return ParsedTrack[]
     */
    private function sortParsedTracksForMatching(array $tracks): array
    {
        usort($tracks, function (ParsedTrack $left, ParsedTrack $right): int {
            $leftSourcePriority = $left->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE ? 0 : 1;
            $rightSourcePriority = $right->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE ? 0 : 1;

            if ($leftSourcePriority !== $rightSourcePriority) {
                return $leftSourcePriority <=> $rightSourcePriority;
            }

            $leftAlbumPriority = $this->shouldPersistAlbumTitle($left->albumTitle) ? 0 : 1;
            $rightAlbumPriority = $this->shouldPersistAlbumTitle($right->albumTitle) ? 0 : 1;

            if ($leftAlbumPriority !== $rightAlbumPriority) {
                return $leftAlbumPriority <=> $rightAlbumPriority;
            }

            return ((int) ($left->trackNumber ?? 999)) <=> ((int) ($right->trackNumber ?? 999));
        });

        return array_values($tracks);
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @return array<int, array<string, mixed>>
     */
    private function preloadRelevantAlbumTrackSummaries(ParsedArtistPage $page, array $albumSummaries): array
    {
        if ($albumSummaries === []) {
            return [];
        }

        $summaries = [];
        $seenAlbumIds = [];

        foreach ($this->sortParsedTracksForMatching($page->tracks) as $parsedTrack) {
            if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
                continue;
            }

            $albumCandidates = $this->buildAlbumTrackFallbackCandidates($parsedTrack, $albumSummaries);

            if ($albumCandidates === []) {
                continue;
            }

            $inspectionLimit = min(
                12,
                max(6, $this->albumTrackFallbackInspectionLimit($albumCandidates) + 2)
            );

            foreach (array_slice($albumCandidates, 0, $inspectionLimit) as $albumCandidate) {
                $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
                $albumId = (int) ($albumPayload['id'] ?? 0);

                if ($albumId <= 0 || isset($seenAlbumIds[$albumId])) {
                    continue;
                }

                $seenAlbumIds[$albumId] = true;
                $songs = $this->albumTrackSongs($albumId, $albumPayload);

                if ($songs !== []) {
                    $summaries = $this->mergeSongSummaries($summaries, $songs);
                }
            }
        }

        $this->matchingLog('debug', 'Genius album-track summaries preloaded.', [
            'artist' => $page->artistName,
            'matched_album_count' => count($seenAlbumIds),
            'preloaded_song_summary_count' => count($summaries),
        ]);

        return $summaries;
    }

    /**
     * @return array{artist:Artist, artists:int, albums:int, tracks:int, matched_tracks:int, unmatched_tracks:int, genius_matched:bool}
     */
    public function syncArtistPage(ParsedArtistPage $page): array
    {
        $this->albumPageAlbumContext = [];
        $this->localArtistAlbums = [];
        $this->currentSyncArtist = null;
        $this->currentPageArtistReferenceNames = $this->pageArtistReferenceNames($page);
        $this->currentSongExactTitleIndex = [];
        $this->currentSongLooseTitleIndex = [];
        $this->preferredAlbumBySongId = [];
        $this->preferredAlbumCandidatesByTitle = [];
        $this->currentArtistAlbumSummaries = [];
        $this->currentArtistGeniusId = 0;

        [$artist, $artistCreated] = $this->upsertPrimaryArtistFromMuzofond($page);
        $this->currentSyncArtist = $artist;
        $artistsCreated = $artistCreated ? 1 : 0;
        $albumsCreated = 0;
        $tracksCreated = 0;
        $matchedTracks = 0;
        $unmatchedTracks = 0;

        $geniusArtist = $this->matchArtist($page);
        $usedSongIds = [];

        if (! $geniusArtist) {
            $this->primeAlbumPageAlbumContext($artist, $page);

            foreach ($page->tracks as $parsedTrack) {
                $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                [$track, $trackCreated] = $this->upsertTrackFallback($artist, $this->resolveFallbackAlbumId($artist, $parsedTrack), $parsedTrack);
                $tracksCreated += $trackCreated ? 1 : 0;
                $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());
            }

            $this->cleanupEmptyAlbumsForArtist($artist);

            return [
                'artist' => $artist,
                'artists' => $artistsCreated,
                'albums' => $albumsCreated,
                'tracks' => $tracksCreated,
                'matched_tracks' => 0,
                'unmatched_tracks' => 0,
                'genius_matched' => false,
            ];
        }

        [$artist, $artistWasCreatedFromGenius] = $this->upsertArtistFromGenius($artist, $geniusArtist);
        $this->currentSyncArtist = $artist;
        $this->currentArtistGeniusId = (int) ($geniusArtist['id'] ?? 0);
        $artistsCreated += $artistWasCreatedFromGenius ? 1 : 0;
        $this->primeAlbumPageAlbumContext($artist, $page);
        $deferredAlbumPageTracks = [];
        $orderedParsedTracks = $this->sortParsedTracksForMatching($page->tracks);

        /**
         * Build a complete in-memory Genius artist catalog up-front:
         * all artist albums + all artist songs, then enrich it with relevant album-track pages
         * for album-page Muzofond rows before the first matching pass.
         */
        $albumSummaries = collect($this->geniusClient->allArtistAlbums((int) $geniusArtist['id']))
            ->filter(fn ($album) => is_array($album))
            ->values()
            ->all();
        $this->currentArtistAlbumSummaries = $albumSummaries;

        $songSummaries = collect($this->geniusClient->allArtistSongs((int) $geniusArtist['id']))
            ->reject(function (array $song): bool {
                $title = GeniusNameMatcher::normalizeLoose((string) ($song['title'] ?? ''));

                return str_contains($title, 'romanized') || str_contains($title, 'translation');
            })
            ->values()
            ->all();

        $preloadedAlbumTrackSummaries = $this->preloadRelevantAlbumTrackSummaries($page, $albumSummaries);

        if ($preloadedAlbumTrackSummaries !== []) {
            $songSummaries = $this->mergeSongSummaries($songSummaries, $preloadedAlbumTrackSummaries);
        }

        $this->primeSongSummaryIndexes($songSummaries);

        $this->matchingLog('debug', 'Genius artist catalog primed for sync.', [
            'artist' => $page->artistName,
            'genius_artist_id' => (int) ($geniusArtist['id'] ?? 0),
            'album_count' => count($albumSummaries),
            'song_summary_count' => count($songSummaries),
            'preloaded_album_track_summary_count' => count($preloadedAlbumTrackSummaries),
        ]);

        foreach ($orderedParsedTracks as $parsedTrack) {
            try {
                $matchedSong = $this->findBestSongMatch(
                    $page,
                    $parsedTrack,
                    $this->songCatalogCandidatesForTrack($parsedTrack, $songSummaries),
                    $usedSongIds,
                    true,
                    $albumSummaries,
                );

                if (! $matchedSong) {
                    $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                    [$track, $trackCreated] = $this->upsertTrackFallback($artist, $this->resolveFallbackAlbumId($artist, $parsedTrack), $parsedTrack);
                    $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());

                    if ($this->shouldRetryAlbumPageTrackMatch($parsedTrack)) {
                        $deferredAlbumPageTracks[] = $parsedTrack;
                    }

                    $trackResult = ['matched' => false, 'created' => $trackCreated];
                } else {
                    [$track, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $matchedSong['detail']);
                    $this->rememberAlbumPageContextFromTrack($artist, $parsedTrack, $track);
                    $trackResult = ['matched' => true, 'created' => $trackCreated];
                }

                if ($matchedSong && (int) ($matchedSong['summary']['id'] ?? 0) > 0) {
                    $usedSongIds[] = (int) $matchedSong['summary']['id'];
                }

                if (! $trackResult['matched']) {
                    $unmatchedTracks++;
                    $tracksCreated += $trackResult['created'] ? 1 : 0;

                    continue;
                }

                $matchedTracks++;
                $tracksCreated += $trackResult['created'] ? 1 : 0;
            } catch (Throwable $exception) {
                $unmatchedTracks++;

                $this->matchingLog('warning', 'Genius sync skipped one track because of an exception.', [
                    'artist' => $page->artistName,
                    'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($deferredAlbumPageTracks !== []) {
            $this->primeAlbumPageAlbumContext($artist, new ParsedArtistPage(
                artistName: $page->artistName,
                artistSlug: $page->artistSlug,
                imageUrl: $page->imageUrl,
                tracks: $deferredAlbumPageTracks,
            ));

            foreach ($deferredAlbumPageTracks as $parsedTrack) {
                if (! $this->resolveAlbumPageContextAlbum($artist, $parsedTrack) instanceof Album) {
                    continue;
                }

                try {
                    $matchedSong = $this->findBestSongMatch(
                        $page,
                        $parsedTrack,
                        $this->songCatalogCandidatesForTrack($parsedTrack, $songSummaries),
                        $usedSongIds,
                        true,
                        $albumSummaries,
                    );

                    if (! $matchedSong) {
                        continue;
                    }

                    [$track, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $matchedSong['detail']);
                    $this->rememberAlbumPageContextFromTrack($artist, $parsedTrack, $track);

                    if ((int) ($matchedSong['summary']['id'] ?? 0) > 0) {
                        $usedSongIds[] = (int) $matchedSong['summary']['id'];
                    }

                    $matchedTracks++;
                    $unmatchedTracks = max(0, $unmatchedTracks - 1);
                    $tracksCreated += $trackCreated ? 1 : 0;
                } catch (Throwable $exception) {
                    $this->matchingLog('warning', 'Genius sync retry skipped one deferred album-page track because of an exception.', [
                        'artist' => $page->artistName,
                        'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->cleanupEmptyAlbumsForArtist($artist);

        return [
            'artist' => $artist,
            'artists' => $artistsCreated,
            'albums' => $albumsCreated,
            'tracks' => $tracksCreated,
            'matched_tracks' => $matchedTracks,
            'unmatched_tracks' => $unmatchedTracks,
            'genius_matched' => true,
        ];
    }

    private function primeAlbumPageAlbumContext(Artist $artist, ParsedArtistPage $page): void
    {
        foreach ($page->tracks as $parsedTrack) {
            if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
                continue;
            }

            $albumId = $this->resolveFallbackAlbumId($artist, $parsedTrack);

            if ($albumId === null) {
                continue;
            }

            $this->albumPageAlbumContext[$this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle)] = $albumId;
        }
    }

    private function rememberAlbumPageContextFromTrack(Artist $artist, ParsedTrack $parsedTrack, ?Track $track): void
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            || ! $track instanceof Track
            || (int) ($track->album_id ?? 0) <= 0) {
            return;
        }

        $this->albumPageAlbumContext[$this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle)] = (int) $track->album_id;
    }

    private function shouldRetryAlbumPageTrackMatch(ParsedTrack $parsedTrack): bool
    {
        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && ! in_array('ringtone', $this->versionFlags($parsedTrack->title), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     */
    private function primeSongSummaryIndexes(array $songSummaries): void
    {
        $this->currentSongExactTitleIndex = [];
        $this->currentSongLooseTitleIndex = [];

        foreach ($songSummaries as $songSummary) {
            if (! is_array($songSummary)) {
                continue;
            }

            $title = (string) ($songSummary['title'] ?? '');
            $canonicalTitle = GeniusNameMatcher::canonicalTrack($title);
            $looseTitle = GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($title));

            if ($canonicalTitle !== '') {
                $this->currentSongExactTitleIndex[$canonicalTitle][] = $songSummary;
            }

            if ($looseTitle !== '') {
                $this->currentSongLooseTitleIndex[$looseTitle][] = $songSummary;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @return array<int, array<string, mixed>>
     */
    private function songCatalogCandidatesForTrack(ParsedTrack $parsedTrack, array $songSummaries): array
    {
        if (count($songSummaries) < 120 || $this->currentSongExactTitleIndex === []) {
            return $songSummaries;
        }

        $canonicalTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $looseTitle = GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($parsedTrack->title));
        $candidates = [];

        if ($canonicalTitle !== '' && isset($this->currentSongExactTitleIndex[$canonicalTitle])) {
            $candidates = $this->mergeSongSummaries($candidates, $this->currentSongExactTitleIndex[$canonicalTitle]);
        }

        if ($looseTitle !== '' && isset($this->currentSongLooseTitleIndex[$looseTitle])) {
            $candidates = $this->mergeSongSummaries($candidates, $this->currentSongLooseTitleIndex[$looseTitle]);
        }

        return $candidates !== [] ? $candidates : $songSummaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatch(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        array $usedSongIds = [],
        bool $allowSearchFallback = true,
        array $albumSummaries = [],
    ): ?array
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;

        if ($parsedTitle === '') {
            return null;
        }

        $candidates = $this->buildSongMatchCandidates($page, $parsedTrack, $songSummaries, $usedSongIds, $parsedTitle, $parsedAlbumTitle);

        if ($candidates === []) {
            $albumTrackMatch = $this->findBestSongMatchFromAlbumTracks($page, $parsedTrack, $albumSummaries, $usedSongIds);

            if ($albumTrackMatch !== null) {
                $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], $albumTrackMatch, 'album-tracks-empty-catalog');

                return $albumTrackMatch;
            }

            $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], null, 'catalog-empty');

            return $allowSearchFallback
                ? $this->findBestSongMatchFromSearch($page, $parsedTrack, $usedSongIds)
                : null;
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $candidates = $this->trimSongMatchCandidates($parsedTrack, $candidates);

        $resolvedCandidates = $this->resolveSongMatchCandidates($page, $parsedTrack, $candidates, $parsedTitle, $parsedAlbumTitle);

        if ($this->shouldTryAlbumTrackFallback($parsedTrack, $resolvedCandidates, $albumSummaries)) {
            $albumTrackMatch = $this->findBestSongMatchFromAlbumTracks($page, $parsedTrack, $albumSummaries, $usedSongIds);

            if ($albumTrackMatch !== null) {
                $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $albumTrackMatch, 'album-tracks');

                return $albumTrackMatch;
            }
        }

        if ($allowSearchFallback && $this->shouldSearchForBetterSongMatch($parsedAlbumTitle, $candidates, $resolvedCandidates)) {
            $searchResults = $this->searchSongSummaries($page, $parsedTrack);

            if ($searchResults !== []) {
                $searchMatch = $this->findBestSongMatch(
                    $page,
                    $parsedTrack,
                    $this->mergeSongSummaries($songSummaries, $searchResults),
                    $usedSongIds,
                    false,
                );

                if ($searchMatch !== null) {
                    $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $searchMatch, 'search-merged');

                    return $searchMatch;
                }
            }
        }

        if ($resolvedCandidates === []) {
            $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, null, 'catalog');

            return null;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate) || ! is_array($bestCandidate['summary'] ?? null) || ! is_array($bestCandidate['detail'] ?? null)) {
            $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, null, 'catalog');

            return null;
        }

        $matchedSong = [
            'summary' => $bestCandidate['summary'],
            'detail' => $bestCandidate['detail'],
        ];

        $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $matchedSong, 'catalog');

        return $matchedSong;
    }

    /**
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatchFromSearch(ParsedArtistPage $page, ParsedTrack $parsedTrack, array $usedSongIds = []): ?array
    {
        $searchResults = $this->searchSongSummaries($page, $parsedTrack);

        if ($searchResults === []) {
            $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], null, 'search-empty');

            return null;
        }

        return $this->findBestSongMatch($page, $parsedTrack, $searchResults, $usedSongIds, false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchSongSummaries(ParsedArtistPage $page, ParsedTrack $parsedTrack): array
    {
        $cacheKey = md5(implode('|', [
            GeniusNameMatcher::storageValue($page->artistName),
            GeniusNameMatcher::storageValue($parsedTrack->title),
            GeniusNameMatcher::storageValue((string) ($parsedTrack->albumTitle ?? '')),
        ]));

        if (! array_key_exists($cacheKey, $this->searchSongSummaryCache)) {
            $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
                ? $this->currentPageArtistReferenceNames
                : $this->pageArtistReferenceNames($page);
            $queries = collect($artistReferenceNames)
                ->flatMap(fn (string $artistName) => GeniusNameMatcher::songSearchQueries($artistName, $parsedTrack->title, $parsedTrack->albumTitle))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
            $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
                : null;
            $mergedSummaries = [];
            $maxQueries = $this->searchQueryAttemptLimit($parsedTrack);

            foreach (array_slice($queries, 0, $maxQueries) as $queryIndex => $query) {
                $mergedSummaries = $this->mergeSongSummaries(
                    $mergedSummaries,
                    collect($this->geniusClient->searchSongs($query))
                        ->filter(fn ($song) => is_array($song))
                        ->values()
                        ->all(),
                );

                if ($this->searchSummaryPoolIsDecisive($page, $parsedTrack, $mergedSummaries, $parsedTitle, $parsedAlbumTitle)) {
                    break;
                }
            }

            $this->searchSongSummaryCache[$cacheKey] = $mergedSummaries;
        }

        return $this->searchSongSummaryCache[$cacheKey];
    }

    private function searchQueryAttemptLimit(ParsedTrack $parsedTrack): int
    {
        if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return $this->versionFlags($parsedTrack->title) === [] ? 8 : 10;
        }

        return $this->versionFlags($parsedTrack->title) === [] ? 6 : 8;
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     */
    private function searchSummaryPoolIsDecisive(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
    ): bool {
        $candidates = $this->buildSongMatchCandidates($page, $parsedTrack, $songSummaries, [], $parsedTitle, $parsedAlbumTitle);

        if ($candidates === []) {
            return false;
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $candidates[0] ?? null;

        if (! is_array($bestCandidate)) {
            return false;
        }

        if (! (bool) ($bestCandidate['exact_title'] ?? false)) {
            return false;
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && (bool) ($bestCandidate['non_original_album'] ?? false)) {
            return false;
        }

        if ($parsedAlbumTitle !== null && (int) ($bestCandidate['album_preference'] ?? 0) < 2) {
            return false;
        }

        return round((float) ($bestCandidate['title_priority'] ?? 0.0), 4) >= 0.94;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     * @param  array<int, array<string, mixed>>  $albumSummaries
     */
    private function shouldTryAlbumTrackFallback(ParsedTrack $parsedTrack, array $resolvedCandidates, array $albumSummaries): bool
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle) || $albumSummaries === []) {
            return false;
        }

        if ($resolvedCandidates === []) {
            return true;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate)) {
            return true;
        }

        if ((int) ($bestCandidate['album_preference'] ?? 0) < 3) {
            return true;
        }

        if ((bool) ($bestCandidate['non_original_album'] ?? false) && $this->versionFlags($parsedTrack->albumTitle ?? '') === []) {
            return true;
        }

        return $parsedTrack->releaseYear !== null && (float) ($bestCandidate['release_year_score'] ?? 0.0) < 0.12;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @param  int[]  $usedSongIds
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatchFromAlbumTracks(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $albumSummaries,
        array $usedSongIds = [],
    ): ?array {
        $albumCandidates = $this->buildAlbumTrackFallbackCandidates($parsedTrack, $albumSummaries);

        if ($albumCandidates === []) {
            $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, [], [], null);

            return null;
        }

        $albumTrackSongs = [];
        $inspectedAlbums = [];

        foreach (array_slice($albumCandidates, 0, $this->albumTrackFallbackInspectionLimit($albumCandidates)) as $albumCandidate) {
            $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            $songs = $this->albumTrackSongs($albumId, $albumPayload);
            $inspectedAlbums[] = [
                'album' => $albumCandidate,
                'tracks' => count($songs),
            ];

            if ($songs === []) {
                continue;
            }

            $albumTrackSongs = $this->mergeSongSummaries($albumTrackSongs, $songs);

            if (count($albumTrackSongs) >= 60) {
                break;
            }
        }

        if ($albumTrackSongs === []) {
            $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, $albumCandidates, $inspectedAlbums, null);

            return null;
        }

        $match = $this->findBestSongMatch($page, $parsedTrack, $albumTrackSongs, $usedSongIds, false);

        $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, $albumCandidates, $inspectedAlbums, $match);

        return $match;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @return array<int, array<string, mixed>>
     */
    private function buildAlbumTrackFallbackCandidates(ParsedTrack $parsedTrack, array $albumSummaries): array
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return [];
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);
        $parsedAlbumComparisonTitle = $this->normalizeAlbumComparisonTitle($parsedAlbumTitle);
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $candidates = [];

        foreach ($albumSummaries as $albumSummary) {
            if (! is_array($albumSummary) || empty($albumSummary['id'])) {
                continue;
            }

            $albumTitle = $this->storedAlbumTitle((string) ($albumSummary['name'] ?? $albumSummary['title'] ?? ''));

            if ($albumTitle === '') {
                continue;
            }

            $albumSimilarity = GeniusNameMatcher::score(
                $parsedAlbumComparisonTitle,
                $this->normalizeAlbumComparisonTitle($albumTitle),
            );
            $albumScore = $this->albumMatchAdjustment($parsedAlbumTitle, $albumTitle, $parsedTrack->title, $parsedTrack->title);
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $albumSummary);
            $nonOriginalAlbum = $this->hasNonOriginalAlbumMarkers($albumTitle);
            $compilation = $this->hasCompilationMarkers($albumTitle);
            $demo = $this->hasDemoMarkers($albumTitle);

            if ($albumSimilarity < 0.68 && $albumScore < -0.04) {
                continue;
            }

            $candidates[] = [
                'album' => $albumSummary,
                'album_title' => $albumTitle,
                'album_similarity' => $albumSimilarity,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'non_original_album' => $nonOriginalAlbum,
                'demo' => $demo,
                'compilation' => $compilation,
                'prefer_original_album' => $preferOriginalAlbum,
                'release_sort' => $this->releaseSortValue($albumSummary),
                'score' => $albumSimilarity + $albumScore + $releaseYearScore + ($nonOriginalAlbum ? -0.12 : 0.04) + ($demo ? -0.08 : 0.02) + ($compilation ? -0.08 : 0.0),
            ];
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareAlbumTrackFallbackCandidates($left, $right));

        return $candidates;
    }

    private function compareAlbumTrackFallbackCandidates(array $left, array $right): int
    {
        $preferOriginalAlbum = (bool) ($left['prefer_original_album'] ?? false);
        $leftAlbumSimilarity = round((float) ($left['album_similarity'] ?? 0.0), 4);
        $rightAlbumSimilarity = round((float) ($right['album_similarity'] ?? 0.0), 4);

        if ($preferOriginalAlbum) {
            if (($left['non_original_album'] ?? false) !== ($right['non_original_album'] ?? false)) {
                return ((bool) ($left['non_original_album'] ?? false)) <=> ((bool) ($right['non_original_album'] ?? false));
            }

            if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
            }

            if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
            }
        }

        $leftAlbumScore = round((float) ($left['album_score'] ?? 0.0), 4);
        $rightAlbumScore = round((float) ($right['album_score'] ?? 0.0), 4);

        if (! $preferOriginalAlbum && $leftAlbumSimilarity !== $rightAlbumSimilarity) {
            return $rightAlbumSimilarity <=> $leftAlbumSimilarity;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

        if ($leftReleaseYearScore !== $rightReleaseYearScore) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        if ($leftAlbumSimilarity !== $rightAlbumSimilarity) {
            return $rightAlbumSimilarity <=> $leftAlbumSimilarity;
        }

        if ($leftAlbumScore !== $rightAlbumScore) {
            return $rightAlbumScore <=> $leftAlbumScore;
        }

        return round((float) ($right['score'] ?? 0.0), 4) <=> round((float) ($left['score'] ?? 0.0), 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumCandidates
     */
    private function albumTrackFallbackInspectionLimit(array $albumCandidates): int
    {
        $strongCandidates = collect($albumCandidates)
            ->filter(function (array $candidate): bool {
                return (float) ($candidate['album_similarity'] ?? 0.0) >= 0.88
                    || (float) ($candidate['album_score'] ?? 0.0) >= 0.12;
            })
            ->count();

        return max(6, min(12, max(8, $strongCandidates + 1)));
    }

    private function parsedTrackPrefersOriginalAlbum(ParsedTrack $parsedTrack): bool
    {
        return array_intersect($this->versionFlags($parsedTrack->title), [
            'instrumental',
            'karaoke',
            'live',
            'remix',
            'remixes',
            'acoustic',
            'edit',
            'ep',
            'deluxe',
            'ver2',
            'remaster',
            'ringtone',
        ]) === [];
    }

    private function parsedTrackUsesTrackNumberSignal(ParsedTrack $parsedTrack): bool
    {
        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $parsedTrack->trackNumber !== null
            && $parsedTrack->trackNumber > 0
            && $parsedTrack->trackNumber <= 80;
    }

    private function directTrackNumberFromPayload(array $payload): ?int
    {
        $trackNumber = (int) (
            $payload['song_number']
                ?? $payload['track_number']
                ?? $payload['number']
                ?? data_get($payload, 'album_appearance.track_number')
                ?? 0
        );

        return $trackNumber > 0 && $trackNumber <= 80
            ? $trackNumber
            : null;
    }

    private function extractSongTrackNumber(array $payload): ?int
    {
        $directTrackNumber = $this->directTrackNumberFromPayload($payload);

        if ($directTrackNumber !== null) {
            return $directTrackNumber;
        }

        $albumId = (int) data_get($payload, 'album.id', 0);
        $songId = (int) ($payload['id'] ?? 0);

        if ($albumId <= 0 || $songId <= 0) {
            return null;
        }

        $trackNumbers = $this->geniusClient->albumTrackNumbers(
            $albumId,
            is_string(data_get($payload, 'album.url')) ? (string) data_get($payload, 'album.url') : null,
        );

        return isset($trackNumbers[$songId]) && (int) $trackNumbers[$songId] > 0 && (int) $trackNumbers[$songId] <= 80
            ? (int) $trackNumbers[$songId]
            : null;
    }

    private function trackNumberMatchAdjustment(ParsedTrack $parsedTrack, ?int $songTrackNumber): float
    {
        if (! $this->parsedTrackUsesTrackNumberSignal($parsedTrack) || $songTrackNumber === null) {
            return 0.0;
        }

        $delta = abs((int) $parsedTrack->trackNumber - $songTrackNumber);

        return match (true) {
            $delta === 0 => 0.18,
            $delta === 1 => 0.06,
            $delta === 2 => -0.06,
            default => -0.18,
        };
    }

    /**
     * @param  array<string, mixed>  $albumPayload
     * @return array<int, array<string, mixed>>
     */
    private function albumTrackSongs(int $albumId, array $albumPayload): array
    {
        if (array_key_exists($albumId, $this->albumTrackSongCache)) {
            return $this->albumTrackSongCache[$albumId];
        }

        try {
            $tracks = $this->geniusClient->albumTracks($albumId);
        } catch (Throwable $exception) {
            $this->matchingLog('warning', 'Genius album tracks lookup failed, cached as empty for this sync.', [
                'album_id' => $albumId,
                'exception' => $exception->getMessage(),
            ]);

            return $this->albumTrackSongCache[$albumId] = [];
        }

        $songs = collect($tracks)
            ->map(function (array $track) use ($albumPayload): ?array {
                $songPayload = is_array($track['song'] ?? null) ? $track['song'] : $track;

                if (! is_array($songPayload) || empty($songPayload['id'])) {
                    return null;
                }

                $albumName = (string) ($albumPayload['name'] ?? $albumPayload['title'] ?? '');
                $albumTitle = (string) ($albumPayload['title'] ?? $albumPayload['name'] ?? '');
                $albumUrl = (string) ($albumPayload['url'] ?? '');
                $albumReleaseDate = (string) ($albumPayload['release_date'] ?? '');
                $albumReleaseDateComponents = is_array($albumPayload['release_date_components'] ?? null)
                    ? $albumPayload['release_date_components']
                    : null;

                $songPayload['album'] = array_filter([
                    'id' => (int) ($albumPayload['id'] ?? 0),
                    'name' => $albumName,
                    'title' => $albumTitle,
                    'url' => $albumUrl,
                    'release_date' => $albumReleaseDate,
                    'release_date_components' => $albumReleaseDateComponents,
                ], fn ($value) => $value !== null && $value !== '' && $value !== []);

                if (! isset($songPayload['release_date']) && $albumReleaseDate !== '') {
                    $songPayload['release_date'] = $albumReleaseDate;
                }

                if (! is_array($songPayload['release_date_components'] ?? null) && $albumReleaseDateComponents !== null) {
                    $songPayload['release_date_components'] = $albumReleaseDateComponents;
                }

                $trackNumber = (int) ($track['number'] ?? $track['track_number'] ?? 0);

                if ($trackNumber > 0) {
                    $songPayload['track_number'] = $trackNumber;
                }

                $songPayload['_wf_source'] = 'album-track';

                return $songPayload;
            })
            ->filter(fn ($song) => is_array($song))
            ->unique(fn (array $song) => (int) ($song['id'] ?? 0))
            ->values()
            ->all();

        $this->albumTrackSongCache[$albumId] = $songs;

        return $songs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @param  int[]  $usedSongIds
     * @return array<int, array<string, mixed>>
     */
    private function buildSongMatchCandidates(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        array $usedSongIds,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
    ): array {
        $candidates = [];
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);

        foreach ($songSummaries as $songSummary) {
            $summaryTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

            if ($summaryTitle === '' || ! $this->songContainsArtist($songSummary, $this->currentPageArtistReferenceNames !== [] ? $this->currentPageArtistReferenceNames : $this->pageArtistReferenceNames($page))) {
                continue;
            }

            $songId = (int) ($songSummary['id'] ?? 0);
            $songAlbumTitle = $this->extractSongAlbumTitle($songSummary);
            $songTitle = (string) ($songSummary['title'] ?? '');
            $titleScore = GeniusNameMatcher::score($parsedTitle, $summaryTitle);
            $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songSummary);
            $songTrackNumber = $this->directTrackNumberFromPayload($songSummary);
            $trackNumberScore = $this->trackNumberMatchAdjustment($parsedTrack, $songTrackNumber);
            $score = $titleScore + $versionScore + $albumScore + $releaseYearScore + $trackNumberScore;
            $isAlbumTrackSummary = (string) ($songSummary['_wf_source'] ?? '') === 'album-track';
            $summaryNonOriginal = ($songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false)
                || $this->hasNonOriginalAlbumMarkers($songTitle);
            $summaryDemo = ($songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false)
                || $this->hasDemoMarkers($songTitle);

            if ($isAlbumTrackSummary && $titleScore < 0.72) {
                continue;
            }

            if ($score < ($parsedAlbumTitle ? 0.66 : 0.76)) {
                continue;
            }

            $artistBoost = 0.04;
            $duplicatePenalty = in_array($songId, $usedSongIds, true) ? 0.06 : 0.0;
            $albumPresenceBoost = $parsedAlbumTitle && $songAlbumTitle ? 0.02 : 0.0;
            $titlePriority = $titleScore + $versionScore;

            $candidates[] = [
                'song' => $songSummary,
                'score' => $score + $artistBoost + $albumPresenceBoost - $duplicatePenalty,
                'title_score' => $titleScore,
                'title_priority' => $titlePriority,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'song_track_number' => $songTrackNumber,
                'track_number_score' => $trackNumberScore,
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'used' => in_array($songId, $usedSongIds, true),
                'exact_title' => $parsedTitle === $summaryTitle,
                'prefer_original_album' => $preferOriginalAlbum,
                'non_original_album' => $summaryNonOriginal,
                'demo' => $summaryDemo,
                'compilation' => $songAlbumTitle ? $this->hasCompilationMarkers($songAlbumTitle) : false,
                'release_sort' => $this->releaseSortValue($songSummary),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function resolveSongMatchCandidates(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $candidates,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
    ): array {
        $resolvedCandidates = [];
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $detailResolutionLimit = $this->detailResolutionLimit($parsedTrack, $candidates);

        foreach (array_slice($candidates, 0, min($detailResolutionLimit, count($candidates))) as $candidate) {
            $songSummary = $candidate['song'] ?? null;

            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            $songDetail = $this->songDetail((int) $songSummary['id']);

            if (! is_array($songDetail)) {
                $songDetail = $songSummary;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);

            if (! $this->songMatchesArtistPage($songDetail, $page)) {
                continue;
            }

            if (! $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            $allowAlbumContextOverride = $this->shouldAllowAlbumPageContextAlbumOverride($parsedTrack, $songDetail);
            $allowTrackNumberContextMatch = $this->shouldAllowAlbumPageContextTrackNumberMatch($parsedTrack, $songDetail);

            if (! $allowAlbumContextOverride
                && ! $allowTrackNumberContextMatch
                && ! $this->songAlbumMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            $songAlbumTitle = $this->extractSongAlbumTitle($songDetail);
            $detailTitleRaw = (string) ($songDetail['title'] ?? '');
            $detailTitle = GeniusNameMatcher::canonicalTrack((string) ($songDetail['title'] ?? ''));
            $titleScore = GeniusNameMatcher::score($parsedTitle, $detailTitle);
            $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songDetail['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songDetail['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songDetail);
            $songTrackNumber = $this->extractSongTrackNumber($songDetail);
            $trackNumberScore = $this->trackNumberMatchAdjustment($parsedTrack, $songTrackNumber);
            $detailNonOriginal = ($songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false)
                || $this->hasNonOriginalAlbumMarkers($detailTitleRaw);
            $detailDemo = ($songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false)
                || $this->hasDemoMarkers($detailTitleRaw);

            $resolvedCandidates[] = array_merge($candidate, [
                'summary' => $songSummary,
                'detail' => $songDetail,
                'score' => max((float) ($candidate['score'] ?? 0.0), $titleScore + $versionScore + $albumScore + $releaseYearScore + $trackNumberScore),
                'title_score' => $titleScore,
                'title_priority' => $titleScore + $versionScore,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'song_track_number' => $songTrackNumber,
                'track_number_score' => $trackNumberScore,
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'exact_title' => $parsedTitle === $detailTitle,
                'prefer_original_album' => $preferOriginalAlbum,
                'non_original_album' => $detailNonOriginal,
                'demo' => $detailDemo,
                'compilation' => $songAlbumTitle ? $this->hasCompilationMarkers($songAlbumTitle) : false,
                'release_sort' => $this->releaseSortValue($songDetail),
            ]);

            $resolvedCandidate = $resolvedCandidates[array_key_last($resolvedCandidates)] ?? null;

            if (is_array($resolvedCandidate) && $this->isDecisiveResolvedSongCandidate($parsedTrack, $resolvedCandidate)) {
                break;
            }
        }

        return $resolvedCandidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function detailResolutionLimit(ParsedTrack $parsedTrack, array $candidates): int
    {
        $baseline = $this->candidateResolutionLimit($parsedTrack);
        $topCandidate = $candidates[0] ?? null;

        if (! is_array($topCandidate)) {
            return $baseline;
        }

        if (! (bool) ($topCandidate['exact_title'] ?? false)) {
            return $baseline;
        }

        if ((float) ($topCandidate['title_priority'] ?? 0.0) >= 0.98
            && (int) ($topCandidate['album_preference'] ?? 0) >= 2
            && ! (bool) ($topCandidate['non_original_album'] ?? false)) {
            return min($baseline, 1);
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && (bool) ($topCandidate['non_original_album'] ?? false)) {
            return min($baseline, 3);
        }

        if ((int) ($topCandidate['album_preference'] ?? 0) >= 3) {
            return min($baseline, 2);
        }

        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            if (! (bool) ($topCandidate['album_present'] ?? false)) {
                return min($baseline, 3);
            }

            return min($baseline, 2);
        }

        return min($baseline, 3);
    }

    private function isDecisiveResolvedSongCandidate(ParsedTrack $parsedTrack, array $candidate): bool
    {
        if (! (bool) ($candidate['exact_title'] ?? false)) {
            return false;
        }

        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && ! (bool) ($candidate['album_present'] ?? false)) {
            return false;
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)) {
            if ((bool) ($candidate['non_original_album'] ?? false)
                || (bool) ($candidate['demo'] ?? false)
                || (bool) ($candidate['compilation'] ?? false)) {
                return false;
            }

            if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                && (int) ($candidate['album_preference'] ?? 0) < 3) {
                return false;
            }
        }

        if ($parsedTrack->releaseYear !== null && (float) ($candidate['release_year_score'] ?? 0.0) < 0.0) {
            return false;
        }

        if ($this->parsedTrackUsesTrackNumberSignal($parsedTrack)
            && ($candidate['song_track_number'] ?? null) !== null
            && (float) ($candidate['track_number_score'] ?? 0.0) < 0.12) {
            return false;
        }

        return (float) ($candidate['title_priority'] ?? 0.0) >= 0.98;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function trimSongMatchCandidates(ParsedTrack $parsedTrack, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $topCandidate = $candidates[0] ?? [];
        $topTitlePriority = (float) ($topCandidate['title_priority'] ?? 0.0);
        $topScore = (float) ($topCandidate['score'] ?? 0.0);
        $scoreWindow = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle) ? 0.24 : 0.18;
        $titleWindow = $this->versionFlags($parsedTrack->title) === [] ? 0.12 : 0.18;

        $filtered = collect($candidates)
            ->filter(function (array $candidate) use ($topScore, $topTitlePriority, $scoreWindow, $titleWindow): bool {
                if ((bool) ($candidate['exact_title'] ?? false)) {
                    return true;
                }

                if ((int) ($candidate['album_preference'] ?? 0) >= 3 && (float) ($candidate['album_score'] ?? 0.0) >= 0.12) {
                    return true;
                }

                return (float) ($candidate['title_priority'] ?? 0.0) >= ($topTitlePriority - $titleWindow)
                    && (float) ($candidate['score'] ?? 0.0) >= ($topScore - $scoreWindow);
            })
            ->values()
            ->all();

        if ($filtered === []) {
            $filtered = $candidates;
        }

        return array_slice($filtered, 0, $this->candidateResolutionLimit($parsedTrack));
    }

    private function candidateResolutionLimit(ParsedTrack $parsedTrack): int
    {
        if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return $this->versionFlags($parsedTrack->title) === [] ? 6 : 8;
        }

        return $this->versionFlags($parsedTrack->title) === [] ? 4 : 6;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function songDetail(int $songId): ?array
    {
        if (! array_key_exists($songId, $this->songDetails)) {
            try {
                $this->songDetails[$songId] = $this->geniusClient->song($songId);
            } catch (Throwable $exception) {
                $this->songDetails[$songId] = null;

                $this->matchingLog('warning', 'Genius song detail lookup failed, cached as null for this sync.', [
                    'song_id' => $songId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $songDetail = $this->songDetails[$songId];

        return is_array($songDetail) ? $songDetail : null;
    }

    /**
     * @param  array<string, mixed>  $songDetail
     * @param  array<string, mixed>  $songSummary
     * @return array<string, mixed>
     */
    private function mergeSongDetailWithSummary(array $songDetail, array $songSummary): array
    {
        if ((string) ($songSummary['_wf_source'] ?? '') !== 'album-track') {
            return $songDetail;
        }

        $summaryAlbum = is_array($songSummary['album'] ?? null) ? $songSummary['album'] : null;

        if ($summaryAlbum !== null) {
            $songDetail['album'] = array_replace(
                is_array($songDetail['album'] ?? null) ? $songDetail['album'] : [],
                $summaryAlbum,
            );
        }

        foreach (['release_date', 'release_date_components', 'track_number'] as $key) {
            if (array_key_exists($key, $songSummary) && $songSummary[$key] !== null && $songSummary[$key] !== '') {
                $songDetail[$key] = $songSummary[$key];
            }
        }

        return $songDetail;
    }

    private function compareSongMatchCandidates(array $left, array $right): int
    {
        if (($left['used'] ?? false) !== ($right['used'] ?? false)) {
            return ((bool) ($left['used'] ?? false)) <=> ((bool) ($right['used'] ?? false));
        }

        $leftExactTitle = (bool) ($left['exact_title'] ?? false);
        $rightExactTitle = (bool) ($right['exact_title'] ?? false);

        if ($leftExactTitle !== $rightExactTitle) {
            return $rightExactTitle <=> $leftExactTitle;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

        if ($leftExactTitle
            && $rightExactTitle
            && abs($leftReleaseYearScore - $rightReleaseYearScore) >= 0.09) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftAlbumPreference = (int) ($left['album_preference'] ?? 0);
        $rightAlbumPreference = (int) ($right['album_preference'] ?? 0);

        if ($leftExactTitle && $rightExactTitle && $leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        $preferOriginalAlbum = (bool) ($left['prefer_original_album'] ?? false);

        if ($preferOriginalAlbum) {
            if ($leftAlbumPreference !== $rightAlbumPreference) {
                return $rightAlbumPreference <=> $leftAlbumPreference;
            }

            $leftNonOriginalAlbum = (bool) ($left['non_original_album'] ?? false);
            $rightNonOriginalAlbum = (bool) ($right['non_original_album'] ?? false);

            if ($leftNonOriginalAlbum !== $rightNonOriginalAlbum) {
                return $leftNonOriginalAlbum <=> $rightNonOriginalAlbum;
            }

            $leftDemo = (bool) ($left['demo'] ?? false);
            $rightDemo = (bool) ($right['demo'] ?? false);

            if ($leftDemo !== $rightDemo) {
                return $leftDemo <=> $rightDemo;
            }

            $leftCompilation = (bool) ($left['compilation'] ?? false);
            $rightCompilation = (bool) ($right['compilation'] ?? false);

            if ($leftCompilation !== $rightCompilation) {
                return $leftCompilation <=> $rightCompilation;
            }

            $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
            $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

            if ($leftReleaseSort !== $rightReleaseSort) {
                return $leftReleaseSort <=> $rightReleaseSort;
            }
        }

        $leftTrackNumberScore = round((float) ($left['track_number_score'] ?? 0.0), 4);
        $rightTrackNumberScore = round((float) ($right['track_number_score'] ?? 0.0), 4);

        if ($leftTrackNumberScore !== $rightTrackNumberScore) {
            return $rightTrackNumberScore <=> $leftTrackNumberScore;
        }

        $leftTitlePriority = round((float) ($left['title_priority'] ?? 0.0), 4);
        $rightTitlePriority = round((float) ($right['title_priority'] ?? 0.0), 4);

        if ($leftTitlePriority !== $rightTitlePriority) {
            return $rightTitlePriority <=> $leftTitlePriority;
        }

        if ($leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        if ($leftReleaseYearScore !== $rightReleaseYearScore) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftNonOriginalAlbum = (bool) ($left['non_original_album'] ?? false);
        $rightNonOriginalAlbum = (bool) ($right['non_original_album'] ?? false);

        if ($leftNonOriginalAlbum !== $rightNonOriginalAlbum) {
            return $leftNonOriginalAlbum <=> $rightNonOriginalAlbum;
        }

        $leftDemo = (bool) ($left['demo'] ?? false);
        $rightDemo = (bool) ($right['demo'] ?? false);

        if ($leftDemo !== $rightDemo) {
            return $leftDemo <=> $rightDemo;
        }

        $leftCompilation = (bool) ($left['compilation'] ?? false);
        $rightCompilation = (bool) ($right['compilation'] ?? false);

        if ($leftCompilation !== $rightCompilation) {
            return $leftCompilation <=> $rightCompilation;
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        return round((float) ($right['score'] ?? 0.0), 4) <=> round((float) ($left['score'] ?? 0.0), 4);
    }

    private function albumPreferenceValue(?string $parsedAlbumTitle, ?string $songAlbumTitle, float $albumScore): int
    {
        if ($songAlbumTitle === null || $songAlbumTitle === '') {
            return $parsedAlbumTitle ? -1 : 0;
        }

        if (! $parsedAlbumTitle) {
            return 2;
        }

        if ($albumScore >= 0.12) {
            return 3;
        }

        if ($albumScore >= -0.04) {
            return 2;
        }

        if ($albumScore > -0.16) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaryCandidates
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     */
    private function shouldSearchForBetterSongMatch(?string $parsedAlbumTitle, array $summaryCandidates, array $resolvedCandidates): bool
    {
        if ($resolvedCandidates === []) {
            return true;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate)) {
            return true;
        }

        if ($parsedAlbumTitle && (int) ($bestCandidate['album_preference'] ?? 0) < 2) {
            return true;
        }

        if ((bool) ($bestCandidate['album_present'] ?? false)) {
            return false;
        }

        if ($parsedAlbumTitle) {
            return true;
        }

        return collect($summaryCandidates)
            ->filter(fn (array $candidate) => (bool) ($candidate['exact_title'] ?? false))
            ->count() >= 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pools
     * @return array<int, array<string, mixed>>
     */
    private function mergeSongSummaries(array ...$pools): array
    {
        $merged = [];

        foreach ($pools as $pool) {
            foreach ($pool as $songSummary) {
                if (! is_array($songSummary) || empty($songSummary['id'])) {
                    continue;
                }

                $songId = (int) $songSummary['id'];
                $current = $merged[$songId] ?? null;

                if (! is_array($current)) {
                    $merged[$songId] = $songSummary;

                    continue;
                }

                $currentRichness = $this->songSummaryRichness($current);
                $incomingRichness = $this->songSummaryRichness($songSummary);

                $merged[$songId] = $incomingRichness > $currentRichness
                    ? array_replace_recursive($current, $songSummary)
                    : array_replace_recursive($songSummary, $current);
            }
        }

        return array_values($merged);
    }

    private function songSummaryRichness(array $songSummary): int
    {
        $score = 0;

        foreach ([
            'album.id',
            'album.name',
            'album.url',
            'release_date',
            'release_date_components.year',
            'primary_artist.id',
            'primary_artist.name',
            'artist_names',
            'primary_artist_names',
        ] as $path) {
            $value = data_get($songSummary, $path);

            if ($value !== null && $value !== '') {
                $score++;
            }
        }

        return $score + count((array) ($songSummary['primary_artists'] ?? [])) + count((array) ($songSummary['featured_artists'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaryCandidates
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     * @param  array{summary: array<string, mixed>, detail: array<string, mixed>}|null  $winner
     */
    private function logSongMatchDiagnostics(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $summaryCandidates,
        array $resolvedCandidates,
        ?array $winner,
        string $source,
    ): void {
        if (! $this->debugMatchingEnabled()) {
            return;
        }

        $this->matchingLog('debug', 'Genius song match diagnostics.', [
            'source' => $source,
            'artist' => $page->artistName,
            'parsed_track' => [
                'title' => $parsedTrack->title,
                'album' => $parsedTrack->albumTitle,
                'year' => $parsedTrack->releaseYear,
                'artists' => $parsedTrack->artistNames,
            ],
            'summary_candidates' => collect($summaryCandidates)
                ->take(8)
                ->map(fn (array $candidate) => $this->songCandidateDebugPayload($candidate, 'song'))
                ->values()
                ->all(),
            'resolved_candidates' => collect($resolvedCandidates)
                ->take(8)
                ->map(fn (array $candidate) => $this->songCandidateDebugPayload($candidate, 'detail'))
                ->values()
                ->all(),
            'winner' => $winner ? [
                'song_id' => (int) data_get($winner, 'summary.id', 0),
                'title' => (string) data_get($winner, 'detail.title', ''),
                'album' => $this->extractSongAlbumTitle((array) ($winner['detail'] ?? [])),
                'release_sort' => $this->releaseSortValue((array) ($winner['detail'] ?? [])),
                'release_year' => (int) data_get($winner, 'detail.release_date_components.year', 0),
            ] : null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumCandidates
     * @param  array<int, array<string, mixed>>  $inspectedAlbums
     * @param  array{summary: array<string, mixed>, detail: array<string, mixed>}|null  $winner
     */
    private function logAlbumTrackFallbackDiagnostics(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $albumCandidates,
        array $inspectedAlbums,
        ?array $winner,
    ): void {
        if (! $this->debugMatchingEnabled()) {
            return;
        }

        $this->matchingLog('debug', 'Genius album-track fallback diagnostics.', [
            'artist' => $page->artistName,
            'parsed_track' => [
                'title' => $parsedTrack->title,
                'album' => $parsedTrack->albumTitle,
                'year' => $parsedTrack->releaseYear,
            ],
            'album_candidates' => collect($albumCandidates)
                ->take(6)
                ->map(fn (array $candidate) => $this->albumFallbackCandidateDebugPayload($candidate))
                ->values()
                ->all(),
            'inspected_albums' => collect($inspectedAlbums)
                ->map(function (array $entry): array {
                    $candidate = is_array($entry['album'] ?? null) ? $entry['album'] : [];

                    return array_merge(
                        $this->albumFallbackCandidateDebugPayload($candidate),
                        ['tracks' => (int) ($entry['tracks'] ?? 0)],
                    );
                })
                ->values()
                ->all(),
            'winner' => $winner ? [
                'song_id' => (int) data_get($winner, 'summary.id', 0),
                'title' => (string) data_get($winner, 'detail.title', ''),
                'album' => $this->extractSongAlbumTitle((array) ($winner['detail'] ?? [])),
                'release_sort' => $this->releaseSortValue((array) ($winner['detail'] ?? [])),
            ] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function songCandidateDebugPayload(array $candidate, string $payloadKey): array
    {
        $payload = is_array($candidate[$payloadKey] ?? null) ? $candidate[$payloadKey] : [];

        return [
            'song_id' => (int) ($payload['id'] ?? 0),
            'title' => (string) ($payload['title'] ?? ''),
            'album' => $this->extractSongAlbumTitle($payload),
            'score' => round((float) ($candidate['score'] ?? 0.0), 4),
            'title_priority' => round((float) ($candidate['title_priority'] ?? 0.0), 4),
            'album_score' => round((float) ($candidate['album_score'] ?? 0.0), 4),
            'release_year_score' => round((float) ($candidate['release_year_score'] ?? 0.0), 4),
            'release_sort' => (int) ($candidate['release_sort'] ?? PHP_INT_MAX),
            'exact_title' => (bool) ($candidate['exact_title'] ?? false),
            'album_preference' => (int) ($candidate['album_preference'] ?? 0),
            'non_original_album' => (bool) ($candidate['non_original_album'] ?? false),
            'demo' => (bool) ($candidate['demo'] ?? false),
            'compilation' => (bool) ($candidate['compilation'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function albumFallbackCandidateDebugPayload(array $candidate): array
    {
        $payload = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];

        return [
            'album_id' => (int) ($payload['id'] ?? 0),
            'title' => (string) ($candidate['album_title'] ?? $payload['name'] ?? $payload['title'] ?? ''),
            'album_similarity' => round((float) ($candidate['album_similarity'] ?? 0.0), 4),
            'album_score' => round((float) ($candidate['album_score'] ?? 0.0), 4),
            'release_year_score' => round((float) ($candidate['release_year_score'] ?? 0.0), 4),
            'release_sort' => (int) ($candidate['release_sort'] ?? PHP_INT_MAX),
            'non_original_album' => (bool) ($candidate['non_original_album'] ?? false),
            'demo' => (bool) ($candidate['demo'] ?? false),
            'compilation' => (bool) ($candidate['compilation'] ?? false),
        ];
    }

    /**
     * @return string[]
     */
    private function pageArtistReferenceNames(ParsedArtistPage $page): array
    {
        $preferredName = $this->preferredPageArtistStorageName($page);
        $slugVariant = GeniusNameMatcher::storageValue(Str::headline(str_replace(['-', '_'], ' ', $page->artistSlug)));
        $references = [
            $preferredName,
            GeniusNameMatcher::storageValue($page->artistName),
            $slugVariant,
        ];

        return collect($references)
            ->filter()
            ->unique(fn (string $value) => Str::lower($value))
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $referenceNames
     * @param  string[]  $candidateNames
     */
    private function bestReferenceArtistScore(array $referenceNames, array $candidateNames): float
    {
        $bestScore = 0.0;

        foreach ($referenceNames as $referenceName) {
            $bestScore = max($bestScore, GeniusNameMatcher::bestArtistScore($referenceName, $candidateNames));
        }

        return $bestScore;
    }

    /**
     * @param  string[]  $referenceNames
     * @param  string[]  $candidateNames
     */
    private function hasExactReferenceArtistMatch(array $referenceNames, array $candidateNames): bool
    {
        foreach ($referenceNames as $referenceName) {
            if (GeniusNameMatcher::bestArtistScore($referenceName, $candidateNames) >= 0.985) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]  $referenceNames
     */
    private function artistCandidateCollectivePenalty(array $referenceNames, string ...$candidateNames): float
    {
        $meaningfulReferenceNames = collect($referenceNames)
            ->filter()
            ->values();

        if ($meaningfulReferenceNames->isEmpty()) {
            return 0.0;
        }

        if ($meaningfulReferenceNames->every(fn (string $name) => $this->hasCollectiveArtistMarkers($name))) {
            return 0.0;
        }

        return collect($candidateNames)
            ->filter()
            ->contains(fn (string $name) => $this->hasCollectiveArtistMarkers($name))
            ? 0.14
            : 0.0;
    }

    private function hasCollectiveArtistMarkers(string $value): bool
    {
        return preg_match('/\b(?:family|records?|label|crew|collective|squad|gang|ent(?:ertainment)?)\b/iu', GeniusNameMatcher::normalizeLoose($value)) === 1;
    }

    private function preferredPageArtistStorageName(ParsedArtistPage $page): string
    {
        $pageName = GeniusNameMatcher::storageValue($page->artistName);
        $slugVariant = GeniusNameMatcher::storageValue(Str::headline(str_replace(['-', '_'], ' ', $page->artistSlug)));

        if ($slugVariant === '' || ! $this->hasCollectiveArtistMarkers($pageName) || $this->hasCollectiveArtistMarkers($slugVariant)) {
            return $pageName;
        }

        $normalizedPageName = GeniusNameMatcher::normalizeLoose($pageName);
        $normalizedSlug = GeniusNameMatcher::normalizeLoose($slugVariant);

        if ($normalizedPageName !== '' && $normalizedSlug !== '' && str_contains($normalizedPageName, $normalizedSlug)) {
            return $slugVariant;
        }

        return $pageName;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchArtist(ParsedArtistPage $page): ?array
    {
        $referenceNames = $this->pageArtistReferenceNames($page);
        $searchQueries = collect($referenceNames)
            ->flatMap(fn (string $referenceName) => GeniusNameMatcher::artistSearchQueries($referenceName))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $candidates = collect($searchQueries)
            ->flatMap(fn (string $query) => $this->geniusClient->searchArtist($query))
            ->unique(fn (array $candidate) => (int) ($candidate['id'] ?? 0))
            ->map(function (array $candidate) use ($referenceNames): array {
                $candidateNames = array_values(array_filter([(string) ($candidate['name'] ?? '')]));

                return [
                    'candidate' => $candidate,
                    'name_score' => $this->bestReferenceArtistScore($referenceNames, $candidateNames),
                    'exact_alias' => $this->hasExactReferenceArtistMatch($referenceNames, $candidateNames),
                    'collective_penalty' => $this->artistCandidateCollectivePenalty($referenceNames, ...$candidateNames),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftExactAlias = (bool) ($left['exact_alias'] ?? false);
                $rightExactAlias = (bool) ($right['exact_alias'] ?? false);

                if ($leftExactAlias !== $rightExactAlias) {
                    return $rightExactAlias <=> $leftExactAlias;
                }

                $leftNameScore = round((float) ($left['name_score'] ?? 0.0), 4);
                $rightNameScore = round((float) ($right['name_score'] ?? 0.0), 4);

                if ($leftNameScore !== $rightNameScore) {
                    return $rightNameScore <=> $leftNameScore;
                }

                return round((float) ($left['collective_penalty'] ?? 0.0), 4) <=> round((float) ($right['collective_penalty'] ?? 0.0), 4);
            })
            ->take(8)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $topCandidate = $candidates->first();

        if (is_array($topCandidate)
            && (bool) ($topCandidate['exact_alias'] ?? false)
            && (float) ($topCandidate['name_score'] ?? 0.0) >= 0.985
            && (float) ($topCandidate['collective_penalty'] ?? 0.0) <= 0.0
            && is_array($topCandidate['candidate'] ?? null)) {
            $artist = $this->geniusClient->artist((int) ($topCandidate['candidate']['id'] ?? 0));

            if (is_array($artist)) {
                return $artist;
            }
        }

        $sampleTrackTitles = collect($page->tracks)
            ->take(10)
            ->map(fn (ParsedTrack $track) => GeniusNameMatcher::canonicalTrack($track->title))
            ->filter()
            ->values()
            ->all();

        $bestScore = 0.0;
        $bestArtist = null;
        $bestExactAliasMatch = false;
        $bestOverlap = 0;

        foreach ($candidates as $candidate) {
            $candidatePayload = is_array($candidate['candidate'] ?? null) ? $candidate['candidate'] : [];
            $artist = $this->geniusClient->artist((int) ($candidatePayload['id'] ?? 0));

            if (! $artist) {
                continue;
            }

            $aliases = array_merge(
                [(string) ($candidatePayload['name'] ?? '')],
                [(string) ($artist['name'] ?? '')],
                array_values((array) ($artist['alternate_names'] ?? [])),
            );

            $nameScore = $this->bestReferenceArtistScore($referenceNames, $aliases);
            $overlap = $this->calculateSongOverlap((int) $artist['id'], $sampleTrackTitles);
            $exactAliasMatch = $this->hasExactReferenceArtistMatch($referenceNames, $aliases);
            $score = $nameScore + ($exactAliasMatch ? 0.16 : 0.0) - $this->artistCandidateCollectivePenalty($referenceNames, ...$aliases);

            if ($overlap >= 3) {
                $score += 0.16;
            } elseif ($overlap === 2) {
                $score += 0.12;
            } elseif ($overlap === 1) {
                $score += 0.05;
            } elseif ($nameScore < 0.9) {
                $score -= 0.08;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestArtist = $artist;
                $bestExactAliasMatch = $exactAliasMatch;
                $bestOverlap = $overlap;
            }
        }

        if (! $bestArtist) {
            return null;
        }

        if ($bestScore >= 0.86) {
            return $bestArtist;
        }

        if ($bestExactAliasMatch && $bestScore >= 0.76) {
            return $bestArtist;
        }

        if ($bestOverlap >= 2 && $bestScore >= 0.78) {
            return $bestArtist;
        }

        return null;
    }

    /**
     * @param  string[]  $sampleTrackTitles
     */
    private function calculateSongOverlap(int $artistId, array $sampleTrackTitles): int
    {
        if ($sampleTrackTitles === []) {
            return 0;
        }

        if (! array_key_exists($artistId, $this->artistSongTitleCatalogCache)) {
            $this->artistSongTitleCatalogCache[$artistId] = collect($this->geniusClient->allArtistSongs($artistId))
                ->take(80)
                ->map(fn (array $song) => GeniusNameMatcher::canonicalTrack((string) ($song['title'] ?? '')))
                ->filter()
                ->flip()
                ->map(fn () => true)
                ->all();
        }

        return collect($sampleTrackTitles)
            ->filter(fn (string $title) => isset($this->artistSongTitleCatalogCache[$artistId][$title]))
            ->count();
    }

    /**
     * @param  string[]  $artistReferenceNames
     */
    private function songContainsArtist(array $songSummary, array $artistReferenceNames): bool
    {
        $artists = array_merge(
            (array) ($songSummary['primary_artists'] ?? []),
            (array) ($songSummary['featured_artists'] ?? []),
        );

        $artistNames = collect($artists)
            ->map(fn ($artist) => is_array($artist) ? (string) ($artist['name'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        if ($artistNames === []) {
            foreach (['artist_names', 'primary_artist_names'] as $key) {
                $value = (string) ($songSummary[$key] ?? '');

                if ($value === '') {
                    continue;
                }

                $artistNames = GeniusNameMatcher::splitArtistCredits($value, $artistReferenceNames[0] ?? null);

                if ($artistNames !== []) {
                    break;
                }
            }
        }

        return $this->bestReferenceArtistScore($artistReferenceNames, $artistNames) >= 0.8;
    }

    /**
     * @param  string[]  $artistReferenceNames
     */
    private function songPrimaryArtistMatchesPage(array $songSummary, array $artistReferenceNames): bool
    {
        $primaryArtistNames = collect((array) ($songSummary['primary_artists'] ?? []))
            ->map(fn ($artist) => is_array($artist) ? (string) ($artist['name'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        if ($primaryArtistNames === [] && is_array($songSummary['primary_artist'] ?? null)) {
            $primaryArtistNames[] = (string) ($songSummary['primary_artist']['name'] ?? '');
        }

        if ($primaryArtistNames === [] && is_string($songSummary['primary_artist_names'] ?? null)) {
            $primaryArtistNames = GeniusNameMatcher::splitArtistCredits((string) $songSummary['primary_artist_names'], $artistReferenceNames[0] ?? null);
        }

        return $this->bestReferenceArtistScore($artistReferenceNames, $primaryArtistNames) >= 0.82;
    }

    private function songMatchesArtistPage(array $songSummary, ParsedArtistPage $page): bool
    {
        $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        return $this->songPrimaryArtistMatchesPage($songSummary, $artistReferenceNames)
            || $this->songContainsArtist($songSummary, $artistReferenceNames);
    }

    private function songTitleMatchesParsedTrack(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));
        $parsedFlags = $this->versionFlags($parsedTrack->title);
        $songFlags = $this->versionFlags((string) ($songPayload['title'] ?? ''));

        if ($parsedTitle === '' || $songTitle === '') {
            return false;
        }

        if (in_array('ringtone', $parsedFlags, true) && ! in_array('ringtone', $songFlags, true)) {
            return false;
        }

        $score = GeniusNameMatcher::score($parsedTitle, $songTitle);
        $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songPayload['title'] ?? ''));

        if ($score >= 0.94) {
            return $versionScore >= -0.18;
        }

        if ($score >= 0.88) {
            return $versionScore >= -0.06;
        }

        return $score >= 0.82 && $versionScore >= 0.0;
    }

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertPrimaryArtistFromMuzofond(ParsedArtistPage $page): array
    {
        $storedName = $this->preferredPageArtistStorageName($page);
        $baseSlug = Str::slug($storedName) ?: 'artist';

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
            ->first();

        $created = false;

        if (! $artist) {
            $artist = Artist::create([
                'name' => $storedName,
                'slug' => $this->uniqueArtistSlug($baseSlug),
                'image_url' => $page->imageUrl,
                'description_preview' => null,
                'genius_id' => null,
            ]);

            $created = true;
        } else {
            $artist->forceFill([
                'name' => $storedName,
                'slug' => $this->uniqueArtistSlugForExisting($baseSlug, $artist->id),
                'image_url' => $artist->image_url ?: $page->imageUrl,
            ])->save();
        }

        return [$artist, $created];
    }

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertArtistFromGenius(?Artist $seedArtist, array $payload): array
    {
        $storedName = GeniusNameMatcher::storageValue((string) ($payload['name'] ?? ''));
        $baseSlug = Str::slug($storedName) ?: 'artist';
        $artist = Artist::query()
            ->where('genius_id', (int) $payload['id'])
            ->first();

        $created = false;

        if (! $artist && $seedArtist && $this->canUseSeedArtistForPayload($seedArtist, $payload)) {
            $artist = $seedArtist;
        }

        if (! $artist && $storedName !== '') {
            $artist = Artist::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
                ->first();
        }

        if (! $artist) {
            foreach ((array) ($payload['alternate_names'] ?? []) as $alternateName) {
                $alternateStoredName = GeniusNameMatcher::storageValue((string) $alternateName);

                if ($alternateStoredName === '') {
                    continue;
                }

                $artist = Artist::query()
                    ->whereRaw('LOWER(name) = ?', [Str::lower($alternateStoredName)])
                    ->first();

                if ($artist) {
                    break;
                }
            }
        }

        if (! $artist) {
            $created = true;
            $artist = new Artist();
            $artist->slug = $this->uniqueArtistSlug($baseSlug);
        } elseif (! $artist->slug) {
            $artist->slug = $this->uniqueArtistSlug($baseSlug);
        }

        $artist->slug = $this->uniqueArtistSlugForExisting($baseSlug, $artist->id);

        $artistPayload = GeniusNameMatcher::sanitizeForDatabase([
            'name' => $storedName,
            'image_url' => $this->geniusClient->artistImageUrl($payload),
            'description_preview' => GeniusNameMatcher::cleanDescriptionPreview($payload['description_preview'] ?? null),
            'genius_id' => (int) $payload['id'],
        ]);

        $artist->forceFill($artistPayload)->save();

        return [$artist, $created];
    }


    private function canUseSeedArtistForPayload(Artist $seedArtist, array $payload): bool
    {
        $payloadId = (int) ($payload['id'] ?? 0);

        if ($payloadId > 0 && $seedArtist->genius_id && (int) $seedArtist->genius_id === $payloadId) {
            return true;
        }

        $aliases = array_merge(
            [(string) ($payload['name'] ?? '')],
            array_values((array) ($payload['alternate_names'] ?? [])),
        );

        return GeniusNameMatcher::bestArtistScore($seedArtist->name, $aliases) >= 0.94;
    }


    private function shouldCreateAlbumFromPayload(array $payload): bool
    {
        $artistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($artistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $artistPayloads = collect([$payload['artist']]);
        }

        if ($artistPayloads->isEmpty()) {
            return true;
        }

        $artistNames = $artistPayloads
            ->map(fn ($artistPayload) => GeniusNameMatcher::storageValue((string) ($artistPayload['name'] ?? '')))
            ->filter()
            ->values();

        if ($artistNames->isEmpty()) {
            return true;
        }

        return $artistNames->contains(fn (string $artistName) => ! $this->isVariousArtistsName($artistName));
    }

    private function rejectVariousArtists(EloquentCollection $artists): EloquentCollection
    {
        if ($artists->count() <= 1) {
            return $artists;
        }

        $filtered = $artists
            ->filter(fn (mixed $artist) => $artist instanceof Artist)
            ->reject(fn (Artist $artist) => $this->isVariousArtistsName($artist->name))
            ->values();

        return $filtered->isNotEmpty()
            ? new EloquentCollection($filtered->all())
            : $artists;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     */
    private function syncArtistAlbums(Artist $artist, array $albumSummaries): int
    {
        foreach ($albumSummaries as $albumSummary) {
            $albumTitle = $this->storedAlbumTitle((string) ($albumSummary['name'] ?? $albumSummary['title'] ?? ''));

            if ($albumTitle === '') {
                continue;
            }

            $existingAlbum = $this->findBestLocalFallbackAlbumMatch($artist, $albumTitle);

            if (! $existingAlbum instanceof Album) {
                continue;
            }

            $albumDetail = $this->shouldCreateAlbumFromPayload($albumSummary)
                ? $albumSummary
                : ($this->geniusClient->album((int) $albumSummary['id']) ?? $albumSummary);

            if (! $this->shouldCreateAlbumFromPayload($albumDetail)) {
                continue;
            }

            $this->refreshExistingAlbumFromGenius($existingAlbum, $artist, $albumDetail);
        }

        return 0;
    }

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbumFromGenius(Artist $fallbackArtist, array $payload): array
    {
        $album = Album::query()
            ->where('genius_id', (int) $payload['id'])
            ->first();

        $created = false;
        $albumTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        if (! $album) {
            $album = Album::query()
                ->where('artist_id', $fallbackArtist->id)
                ->whereRaw('LOWER(title) = ?', [Str::lower($albumTitle)])
                ->first();
        }

        $albumArtistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($albumArtistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $albumArtistPayloads = collect([$payload['artist']]);
        }

        $albumArtists = new EloquentCollection();

        foreach ($albumArtistPayloads as $index => $artistPayload) {
            [$albumArtist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);

            if ($this->isVariousArtistsName($albumArtist->name)) {
                continue;
            }

            $albumArtists->push($albumArtist);
        }

        if ($albumArtists->isEmpty()) {
            if ($album && method_exists($album, 'artists')) {
                $album->loadMissing('artists');

                if ($album->artists->isNotEmpty()) {
                    $albumArtists = new EloquentCollection($album->artists->all());
                }
            }

            if ($albumArtists->isEmpty()) {
                $albumArtists->push($fallbackArtist);
            }
        }

        $albumArtists = $this->rejectVariousArtists(new EloquentCollection(
            $albumArtists
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        /** @var Artist $primaryAlbumArtist */
        $primaryAlbumArtist = $this->resolvePreferredAlbumArtist($fallbackArtist, $albumArtists);
        $previousArtistId = $album?->artist_id;

        if (! $album) {
            $created = true;
            $album = new Album();
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($albumTitle)
                    ?: 'album'
            );
        } elseif (! $album->slug) {
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($albumTitle)
                    ?: 'album'
            );
        }

        $albumPayload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryAlbumArtist->id,
            'title' => $albumTitle,
            'cover_image_url' => $payload['cover_art_thumbnail_url']
                ?? $payload['cover_art_url']
                    ?? $payload['image_url']
                    ?? $album->cover_image_url,
            'release_date' => $payload['release_date'] ?? null,
            'genius_id' => (int) $payload['id'],
        ]);

        try {
            $album->forceFill($albumPayload)->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception, 'albums_genius_id_unique') || (int) ($albumPayload['genius_id'] ?? 0) <= 0) {
                throw $exception;
            }

            $existingAlbum = Album::query()
                ->where('genius_id', (int) $albumPayload['genius_id'])
                ->first();

            if (! $existingAlbum instanceof Album) {
                throw $exception;
            }

            $album = $existingAlbum;
            $created = false;
            $album->forceFill($albumPayload)->save();
        }

        $this->rememberLocalAlbum($album, $previousArtistId);

        if (method_exists($album, 'artists')) {
            $album->artists()->sync($albumArtists->pluck('id')->unique()->values()->all());
        }

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrackFromGenius(Artist $fallbackArtist, ParsedTrack $parsedTrack, array $songPayload): array
    {
        $primaryPerformerPayloads = collect((array) ($songPayload['primary_artists'] ?? []))
            ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
            ->values();

        if ($primaryPerformerPayloads->isEmpty() && is_array($songPayload['primary_artist'] ?? null) && isset($songPayload['primary_artist']['id'])) {
            $primaryPerformerPayloads = collect([$songPayload['primary_artist']]);
        }

        $featuredPerformerPayloads = collect((array) ($songPayload['featured_artists'] ?? []))
            ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
            ->values();

        $primaryPerformers = new EloquentCollection();
        $featuredPerformers = new EloquentCollection();

        foreach ($primaryPerformerPayloads as $index => $artistPayload) {
            [$artist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);
            $primaryPerformers->push($artist);
        }

        foreach ($featuredPerformerPayloads as $artistPayload) {
            [$artist] = $this->upsertArtistFromGenius(null, $artistPayload);
            $featuredPerformers->push($artist);
        }

        $primaryPerformers = $this->rejectVariousArtists(new EloquentCollection(
            $primaryPerformers
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        $featuredPerformers = $this->rejectVariousArtists(new EloquentCollection(
            $featuredPerformers
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        $performers = $this->rejectVariousArtists(new EloquentCollection(
            collect($primaryPerformers)
                ->concat($featuredPerformers)
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        if ($performers->isEmpty()) {
            $performers->push($fallbackArtist);
        }

        $primaryArtist = $this->resolveTrackPrimaryArtist($fallbackArtist, $primaryPerformers, $performers, $parsedTrack);

        if (! $performers->contains(fn (Artist $artist) => $artist->id === $primaryArtist->id)) {
            $performers->prepend($primaryArtist);
        }

        $album = $this->resolveMatchedTrackAlbum($fallbackArtist, $primaryArtist, $parsedTrack, $songPayload);
        $albumId = $album?->id;

        $geniusTitle = GeniusNameMatcher::normalizeStoredTrackTitle((string) ($songPayload['title'] ?? $parsedTrack->title));

        $track = Track::query()
            ->where(function ($query) use ($parsedTrack): void {
                $query->where('audio_url', $parsedTrack->audioUrl);

                if ($this->trackHasColumn('original_link')) {
                    $query->orWhere('original_link', $parsedTrack->audioUrl);
                }
            })
            ->first();

        if (! $track) {
            $track = Track::query()
                ->where('artist_id', $primaryArtist->id)
                ->where('album_id', $albumId)
                ->where('genius_id', (int) ($songPayload['id'] ?? 0))
                ->whereRaw('LOWER(title) = ?', [
                    Str::lower($geniusTitle),
                ])
                ->where('duration_seconds', $parsedTrack->durationSeconds)
                ->first();
        }

        if (! $track) {
            $track = Track::query()
                ->where('artist_id', $primaryArtist->id)
                ->where('album_id', $albumId)
                ->whereRaw('LOWER(title) = ?', [
                    Str::lower($geniusTitle),
                ])
                ->where('duration_seconds', $parsedTrack->durationSeconds)
                ->first();
        }

        if (! $track && (int) ($songPayload['id'] ?? 0) > 0) {
            $track = Track::query()
                ->where('genius_id', (int) $songPayload['id'])
                ->first();
        }

        $created = false;

        if (! $track) {
            $created = true;
            $track = new Track();
        }

        $genres = GeniusNameMatcher::extractGenres((array) ($songPayload['tags'] ?? []));

        $payload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => $geniusTitle,
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
            'release_year' => $this->resolveReleaseYear($songPayload, $parsedTrack),
            'track_number' => $this->resolveTrackNumber($parsedTrack, $songPayload),
            'genres' => $genres !== [] ? $genres : ($parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null),
            'language' => GeniusNameMatcher::resolveLanguageCode((array) ($songPayload['tags'] ?? []), $songPayload['language'] ?? null),
            'description_preview' => GeniusNameMatcher::cleanDescriptionPreview($songPayload['description_preview'] ?? null),
            'genius_url' => $songPayload['url'] ?? null,
            'cover_image_url' => $album ? null : ($songPayload['song_art_image_url'] ?? $songPayload['header_image_url'] ?? null),
            'genius_id' => (int) $songPayload['id'],
            'genius_pageviews' => max(0, (int) floor(((int) data_get($songPayload, 'stats.pageviews', 0)) / 20)),
        ]);

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        if ($this->trackHasColumn('audio_hash')) {
            $payload['audio_hash'] = sha1((string) $parsedTrack->audioUrl);
        }

        if (! $this->trackHasColumn('genius_pageviews')) {
            unset($payload['genius_pageviews']);
        }

        try {
            $track->forceFill($payload)->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception, 'tracks_genius_id_unique') || (int) ($payload['genius_id'] ?? 0) <= 0) {
                throw $exception;
            }

            $existingTrack = Track::query()
                ->where('genius_id', (int) $payload['genius_id'])
                ->first();

            if (! $existingTrack instanceof Track) {
                throw $exception;
            }

            $track = $existingTrack;
            $created = false;
            $track->forceFill($payload)->save();
        }

        $track->artists()->sync($performers->pluck('id')->unique()->values()->all());

        return [$track, $created];
    }

    private function resolveMatchedTrackAlbum(
        Artist $fallbackArtist,
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
    ): ?Album {
        $albumPayload = is_array($songPayload['album'] ?? null) ? $songPayload['album'] : null;
        $albumId = (int) ($albumPayload['id'] ?? 0);

        if ($albumId > 0) {
            $albumPayload = $this->geniusClient->album($albumId) ?? $albumPayload;
        }

        $preferredAlbumPayload = $this->preferredAlbumPayloadForSong($primaryArtist, $parsedTrack, $songPayload, $albumPayload);

        if (is_array($preferredAlbumPayload)) {
            $albumPayload = $preferredAlbumPayload;
            $albumId = (int) ($albumPayload['id'] ?? 0);
        }

        $payloadAlbumTitle = is_array($albumPayload)
            ? $this->extractAlbumPayloadTitle($albumPayload)
            : $this->extractSongAlbumTitle($songPayload);
        $candidateTitles = collect($this->orderedAlbumCandidateTitles(
            $parsedTrack,
            $payloadAlbumTitle,
        ));
        $contextAlbum = $this->resolveAlbumPageContextAlbum($fallbackArtist, $parsedTrack);

        if ($contextAlbum instanceof Album && (
            $this->shouldUseAlbumPageContextAlbum($contextAlbum, $parsedTrack, $payloadAlbumTitle)
            || $this->shouldAllowAlbumPageContextAlbumOverride($parsedTrack, $songPayload, $contextAlbum, $payloadAlbumTitle)
        )) {
            if ($this->shouldRefreshExistingAlbumFromPayload($contextAlbum, $parsedTrack, $albumPayload)) {
                $this->refreshExistingAlbumFromGenius($contextAlbum, $primaryArtist, $albumPayload);
            }

            return $contextAlbum;
        }

        foreach ([$primaryArtist, $fallbackArtist] as $albumArtist) {
            foreach ($candidateTitles as $candidateTitle) {
                $exactAlbum = $this->findLocalAlbumByComparableTitle($albumArtist, $candidateTitle);

                if ($exactAlbum instanceof Album) {
                    if (! $this->shouldUseLocalAlbumCandidate($exactAlbum, $parsedTrack, $candidateTitle, $payloadAlbumTitle)) {
                        continue;
                    }

                    if ($this->shouldRefreshExistingAlbumFromPayload($exactAlbum, $parsedTrack, $albumPayload)) {
                        $this->refreshExistingAlbumFromGenius($exactAlbum, $primaryArtist, $albumPayload);
                    }

                    return $exactAlbum;
                }

                $existingAlbum = $this->findBestLocalFallbackAlbumMatch($albumArtist, $candidateTitle);

                if (! $existingAlbum instanceof Album) {
                    continue;
                }

                if (! $this->shouldUseLocalAlbumCandidate($existingAlbum, $parsedTrack, $candidateTitle, $payloadAlbumTitle)) {
                    continue;
                }

                if ($this->shouldRefreshExistingAlbumFromPayload($existingAlbum, $parsedTrack, $albumPayload)) {
                    $this->refreshExistingAlbumFromGenius($existingAlbum, $primaryArtist, $albumPayload);
                }

                return $existingAlbum;
            }
        }

        if ($albumId > 0) {
            $existingByGeniusId = Album::query()
                ->where('genius_id', $albumId)
                ->first();

            if ($existingByGeniusId instanceof Album) {
                if ($this->shouldRefreshExistingAlbumFromPayload($existingByGeniusId, $parsedTrack, $albumPayload)) {
                    $this->refreshExistingAlbumFromGenius($existingByGeniusId, $primaryArtist, $albumPayload);
                }

                return $existingByGeniusId;
            }
        }

        if (is_array($albumPayload) && $this->shouldCreateAlbumFromPayload($albumPayload)) {
            [$album] = $this->upsertAlbumFromGenius($primaryArtist, $albumPayload);

            return $album;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     * @return array<string, mixed>|null
     */
    private function preferredAlbumPayloadForSong(
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): ?array {
        if (! $this->shouldResolvePreferredAlbumPayload($parsedTrack, $songPayload, $currentAlbumPayload)) {
            return null;
        }

        $artistGeniusId = $this->preferredAlbumArtistGeniusId($primaryArtist, $songPayload);

        if ($artistGeniusId <= 0) {
            return null;
        }

        $preferredFromParsedAlbumTitle = $this->preferredAlbumPayloadFromParsedAlbumTitle(
            $artistGeniusId,
            $parsedTrack,
            $songPayload,
            $currentAlbumPayload,
        );

        if (is_array($preferredFromParsedAlbumTitle)) {
            return $preferredFromParsedAlbumTitle;
        }

        $this->primePreferredAlbumPayloadCatalog($artistGeniusId);

        $songId = (int) ($songPayload['id'] ?? 0);

        if ($songId > 0 && is_array($this->preferredAlbumBySongId[$artistGeniusId][$songId] ?? null)) {
            return $this->preferredAlbumBySongId[$artistGeniusId][$songId];
        }

        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($songTitle === '') {
            return null;
        }

        foreach ($this->preferredAlbumCandidatesByTitle[$artistGeniusId][$songTitle] ?? [] as $candidate) {
            $candidateSong = is_array($candidate['song'] ?? null) ? $candidate['song'] : [];
            $candidateTitle = (string) ($candidateSong['title'] ?? '');

            if ($candidateTitle === '') {
                continue;
            }

            if (GeniusNameMatcher::score($songTitle, GeniusNameMatcher::canonicalTrack($candidateTitle)) < 0.94) {
                continue;
            }

            if ($this->versionMatchAdjustment($parsedTrack->title, $candidateTitle) < -0.18) {
                continue;
            }

            return is_array($candidate['album'] ?? null) ? $candidate['album'] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     * @return array<string, mixed>|null
     */
    private function preferredAlbumPayloadFromParsedAlbumTitle(
        int $artistGeniusId,
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): ?array {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return null;
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);

        if ($parsedAlbumTitle === '') {
            return null;
        }

        $songId = (int) ($songPayload['id'] ?? 0);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($songId <= 0 && $songTitle === '') {
            return null;
        }

        $currentAlbumTitle = is_array($currentAlbumPayload)
            ? $this->extractAlbumPayloadTitle($currentAlbumPayload)
            : $this->extractSongAlbumTitle($songPayload);

        $albumCandidates = collect($this->artistAlbumSummariesForPreferredPayload($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload) && (int) ($albumPayload['id'] ?? 0) > 0)
            ->map(function (array $albumPayload) use ($parsedAlbumTitle, $parsedTrack, $songPayload): ?array {
                $candidateTitle = $this->extractAlbumPayloadTitle($albumPayload) ?? '';

                if ($candidateTitle === '') {
                    return null;
                }

                $identityMatch = $this->albumTitlesShareIdentity($parsedAlbumTitle, $candidateTitle);
                $similarity = $this->albumTitleSimilarity($parsedAlbumTitle, $candidateTitle);

                if (! $identityMatch && $similarity < 0.78) {
                    return null;
                }

                return [
                    'album' => $albumPayload,
                    'similarity' => $similarity,
                    'identity' => $identityMatch,
                    'non_original' => $this->hasNonOriginalAlbumMarkers($candidateTitle),
                    'demo' => $this->hasDemoMarkers($candidateTitle),
                    'compilation' => $this->hasCompilationMarkers($candidateTitle),
                    'single_like' => $this->hasSingleLikeAlbumMarkers($candidateTitle, (string) ($songPayload['title'] ?? $parsedTrack->title)),
                    'release_sort' => $this->releaseSortValue($albumPayload),
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                if (($left['identity'] ?? false) !== ($right['identity'] ?? false)) {
                    return ((bool) ($right['identity'] ?? false)) <=> ((bool) ($left['identity'] ?? false));
                }

                return $this->comparePreferredAlbumPayloadCandidates($left, $right);
            })
            ->values()
            ->all();

        if ($albumCandidates === []) {
            return null;
        }

        if ($currentAlbumTitle !== null && $currentAlbumTitle !== '') {
            foreach ($albumCandidates as $candidate) {
                $candidateAlbum = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];
                $candidateTitle = $this->extractAlbumPayloadTitle($candidateAlbum) ?? '';

                if ($candidateTitle !== '' && $this->albumTitlesShareIdentity($currentAlbumTitle, $candidateTitle)) {
                    return $candidateAlbum;
                }
            }
        }

        foreach (array_slice($albumCandidates, 0, 6) as $candidate) {
            $albumPayload = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            foreach ($this->albumTrackSongs($albumId, $albumPayload) as $songSummary) {
                if (! is_array($songSummary)) {
                    continue;
                }

                $candidateSongId = (int) ($songSummary['id'] ?? 0);
                $candidateSongTitle = (string) ($songSummary['title'] ?? '');

                if ($songId > 0 && $candidateSongId === $songId) {
                    return $albumPayload;
                }

                if ($songTitle === '' || $candidateSongTitle === '') {
                    continue;
                }

                if (GeniusNameMatcher::score($songTitle, GeniusNameMatcher::canonicalTrack($candidateSongTitle)) < 0.94) {
                    continue;
                }

                if ($this->versionMatchAdjustment($parsedTrack->title, $candidateSongTitle) < -0.18) {
                    continue;
                }

                return $albumPayload;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     */
    private function shouldResolvePreferredAlbumPayload(
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): bool {
        if (! $this->parsedTrackPrefersOriginalAlbum($parsedTrack)) {
            return false;
        }

        $currentAlbumTitle = is_array($currentAlbumPayload)
            ? $this->extractAlbumPayloadTitle($currentAlbumPayload)
            : $this->extractSongAlbumTitle($songPayload);

        if ($currentAlbumTitle === null || $currentAlbumTitle === '') {
            return true;
        }

        if ($this->hasNonOriginalAlbumMarkers($currentAlbumTitle)) {
            return true;
        }

        if ($this->hasSingleLikeAlbumMarkers($currentAlbumTitle, (string) ($songPayload['title'] ?? $parsedTrack->title))) {
            return true;
        }

        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return false;
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);

        if ($parsedAlbumTitle === '') {
            return false;
        }

        if (! $this->albumTitlesShareIdentity($parsedAlbumTitle, (string) $currentAlbumTitle)) {
            return true;
        }

        return $this->hasDemoMarkers($currentAlbumTitle)
            || $this->hasCompilationMarkers($currentAlbumTitle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function artistAlbumSummariesForPreferredPayload(int $artistGeniusId): array
    {
        if ($artistGeniusId === $this->currentArtistGeniusId && $this->currentArtistAlbumSummaries !== []) {
            return $this->currentArtistAlbumSummaries;
        }

        return collect($this->geniusClient->allArtistAlbums($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload))
            ->values()
            ->all();
    }

    private function preferredAlbumArtistGeniusId(Artist $primaryArtist, array $songPayload): int
    {
        $artistIds = collect((array) ($songPayload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && (int) ($artistPayload['id'] ?? 0) > 0)
            ->map(fn (array $artistPayload) => (int) ($artistPayload['id'] ?? 0))
            ->values()
            ->all();

        if ($artistIds !== []) {
            return (int) $artistIds[0];
        }

        if ((int) ($primaryArtist->genius_id ?? 0) > 0) {
            return (int) $primaryArtist->genius_id;
        }

        return (int) ($this->currentSyncArtist?->genius_id ?? 0);
    }

    private function primePreferredAlbumPayloadCatalog(int $artistGeniusId): void
    {
        if (array_key_exists($artistGeniusId, $this->preferredAlbumBySongId)) {
            return;
        }

        $this->preferredAlbumBySongId[$artistGeniusId] = [];
        $this->preferredAlbumCandidatesByTitle[$artistGeniusId] = [];

        $albumCandidates = collect($this->artistAlbumSummariesForPreferredPayload($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload) && (int) ($albumPayload['id'] ?? 0) > 0)
            ->map(function (array $albumPayload): array {
                $albumTitle = $this->extractAlbumPayloadTitle($albumPayload) ?? '';

                return [
                    'album' => $albumPayload,
                    'title' => $albumTitle,
                    'single_like' => $this->hasSingleLikeAlbumMarkers($albumTitle),
                    'non_original' => $this->hasNonOriginalAlbumMarkers($albumTitle),
                    'demo' => $this->hasDemoMarkers($albumTitle),
                    'compilation' => $this->hasCompilationMarkers($albumTitle),
                    'release_sort' => $this->releaseSortValue($albumPayload),
                ];
            })
            ->sort(fn (array $left, array $right): int => $this->comparePreferredAlbumPayloadCandidates($left, $right))
            ->values()
            ->all();

        foreach ($albumCandidates as $albumCandidate) {
            $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            foreach ($this->albumTrackSongs($albumId, $albumPayload) as $songSummary) {
                if (! is_array($songSummary)) {
                    continue;
                }

                $songId = (int) ($songSummary['id'] ?? 0);
                $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

                if ($songId > 0 && ! isset($this->preferredAlbumBySongId[$artistGeniusId][$songId])) {
                    $this->preferredAlbumBySongId[$artistGeniusId][$songId] = $albumPayload;
                }

                if ($songTitle === '') {
                    continue;
                }

                $this->preferredAlbumCandidatesByTitle[$artistGeniusId][$songTitle][] = [
                    'album' => $albumPayload,
                    'song' => $songSummary,
                ];
            }
        }
    }

    private function comparePreferredAlbumPayloadCandidates(array $left, array $right): int
    {
        if (($left['single_like'] ?? false) !== ($right['single_like'] ?? false)) {
            return ((bool) ($left['single_like'] ?? false)) <=> ((bool) ($right['single_like'] ?? false));
        }

        if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
            return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
        }

        if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
            return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
        }

        if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
            return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        return ((int) data_get($left, 'album.id', PHP_INT_MAX)) <=> ((int) data_get($right, 'album.id', PHP_INT_MAX));
    }

    private function shouldUseAlbumPageContextAlbum(Album $album, ParsedTrack $parsedTrack, ?string $payloadAlbumTitle): bool
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE) {
            return false;
        }

        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return true;
        }

        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;
        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));

        return ($parsedAlbumTitle !== null && $this->albumTitlesShareIdentity($parsedAlbumTitle, $payloadAlbumTitle))
            || ($currentTitle !== '' && $this->albumTitlesShareIdentity($currentTitle, $payloadAlbumTitle));
    }

    /**
     * @param  array<string, mixed>  $songPayload
     */
    private function shouldAllowAlbumPageContextAlbumOverride(
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?Album $contextAlbum = null,
        ?string $payloadAlbumTitle = null,
    ): bool {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            || in_array('ringtone', $this->versionFlags($parsedTrack->title), true)) {
            return false;
        }

        $currentArtist = $this->currentSyncArtist;

        if (! $currentArtist instanceof Artist) {
            return false;
        }

        $contextAlbum ??= $this->resolveAlbumPageContextAlbum($currentArtist, $parsedTrack);
        $contextAlbum ??= $this->findBestLocalFallbackAlbumMatch($currentArtist, (string) $parsedTrack->albumTitle);

        if (! $contextAlbum instanceof Album) {
            return false;
        }

        $parsedTitle = GeniusNameMatcher::canonicalTrack((string) $parsedTrack->title);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($parsedTitle === '' || $songTitle === '' || $parsedTitle !== $songTitle) {
            return false;
        }

        $payloadAlbumTitle = $this->shouldPersistAlbumTitle($payloadAlbumTitle)
            ? $this->storedAlbumTitle((string) $payloadAlbumTitle)
            : null;

        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return true;
        }

        $normalizedPayloadAlbumTitle = GeniusNameMatcher::canonicalTrack($payloadAlbumTitle);

        if ($normalizedPayloadAlbumTitle !== '' && $normalizedPayloadAlbumTitle === $parsedTitle) {
            return false;
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);

        return $parsedAlbumTitle !== ''
            && ! $this->albumTitlesShareIdentity($parsedAlbumTitle, $payloadAlbumTitle);
    }

    /**
     * @param  array<string, mixed>  $songPayload
     */
    private function shouldAllowAlbumPageContextTrackNumberMatch(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        if (! $this->parsedTrackUsesTrackNumberSignal($parsedTrack)) {
            return false;
        }

        $currentArtist = $this->currentSyncArtist;

        if (! $currentArtist instanceof Artist) {
            return false;
        }

        $contextAlbum = $this->resolveAlbumPageContextAlbum($currentArtist, $parsedTrack);

        if (! $contextAlbum instanceof Album) {
            return false;
        }

        $songTrackNumber = $this->extractSongTrackNumber($songPayload);

        return $songTrackNumber !== null
            && (int) $parsedTrack->trackNumber === $songTrackNumber;
    }

    /**
     * @return string[]
     */
    private function orderedAlbumCandidateTitles(ParsedTrack $parsedTrack, ?string $payloadAlbumTitle): array
    {
        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;
        $payloadAlbumTitle = $this->shouldPersistAlbumTitle($payloadAlbumTitle)
            ? $this->storedAlbumTitle((string) $payloadAlbumTitle)
            : null;

        if ($parsedAlbumTitle === null && $payloadAlbumTitle === null) {
            return [];
        }

        if ($parsedAlbumTitle === null) {
            return [$payloadAlbumTitle];
        }

        if ($payloadAlbumTitle === null) {
            return [$parsedAlbumTitle];
        }

        $similarity = $this->albumTitleSimilarity($parsedAlbumTitle, $payloadAlbumTitle);

        if ($similarity < 0.78) {
            return array_values(array_unique([$payloadAlbumTitle, $parsedAlbumTitle]));
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && ! $this->hasNonOriginalAlbumMarkers($parsedAlbumTitle)
            && $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)) {
            return array_values(array_unique([$parsedAlbumTitle, $payloadAlbumTitle]));
        }

        return array_values(array_unique([$payloadAlbumTitle, $parsedAlbumTitle]));
    }

    private function shouldUseLocalAlbumCandidate(
        Album $album,
        ParsedTrack $parsedTrack,
        string $candidateTitle,
        ?string $payloadAlbumTitle,
    ): bool {
        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return true;
        }

        $candidateTitle = $this->storedAlbumTitle($candidateTitle);
        $payloadAlbumTitle = $this->storedAlbumTitle($payloadAlbumTitle);

        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));
        $prefersOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);

        if ($candidateTitle === '') {
            return true;
        }

        if ($prefersOriginalAlbum
            && $currentTitle !== ''
            && $this->hasNonOriginalAlbumMarkers($currentTitle)
            && (
                ! $this->hasNonOriginalAlbumMarkers($candidateTitle)
                || ! $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)
            )) {
            return false;
        }

        if ($currentTitle !== '' && $this->albumTitlesShareIdentity($currentTitle, $payloadAlbumTitle)) {
            return true;
        }

        if ($prefersOriginalAlbum
            && ! $this->hasNonOriginalAlbumMarkers($candidateTitle)
            && $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)) {
            return true;
        }

        $normalizedCandidateTrackTitle = GeniusNameMatcher::canonicalTrack($currentTitle !== '' ? $currentTitle : $candidateTitle);
        $normalizedParsedTrackTitle = GeniusNameMatcher::canonicalTrack((string) $parsedTrack->title);

        return $normalizedCandidateTrackTitle !== ''
            && $normalizedCandidateTrackTitle === $normalizedParsedTrackTitle;
    }

    private function shouldRefreshExistingAlbumFromPayload(Album $album, ParsedTrack $parsedTrack, ?array $payload): bool
    {
        if (! is_array($payload) || ! $this->shouldCreateAlbumFromPayload($payload)) {
            return false;
        }

        $payloadTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        if ($payloadTitle === '') {
            return false;
        }

        if ((int) ($album->genius_id ?? 0) > 0 && (int) ($album->genius_id ?? 0) === (int) ($payload['id'] ?? 0)) {
            return true;
        }

        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));
        $prefersOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $payloadIsNonOriginal = $this->hasNonOriginalAlbumMarkers($payloadTitle);
        $currentIsNonOriginal = $this->hasNonOriginalAlbumMarkers($currentTitle);

        if ($currentTitle === '') {
            return true;
        }

        if (! $this->albumTitlesShareIdentity($currentTitle, $payloadTitle)) {
            return false;
        }

        if ($prefersOriginalAlbum && $currentIsNonOriginal && ! $payloadIsNonOriginal) {
            return false;
        }

        return ! (
            $prefersOriginalAlbum
            && ! $this->hasNonOriginalAlbumMarkers($currentTitle)
            && $payloadIsNonOriginal
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && $this->albumTitleSimilarity((string) $parsedTrack->albumTitle, $currentTitle) >= 0.82
        );
    }

    private function resolveAlbumPageContextAlbum(Artist $artist, ParsedTrack $parsedTrack): ?Album
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return null;
        }

        $albumId = $this->albumPageAlbumContext[$this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle)] ?? null;

        if (! is_int($albumId) || $albumId <= 0) {
            return null;
        }

        return Album::query()->find($albumId);
    }

    private function albumPageContextKey(Artist $artist, string $albumTitle): string
    {
        return $artist->id . ':' . md5($this->albumComparableLookupKey($albumTitle) . '|' . $this->storedAlbumTitle($albumTitle));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Album>
     */
    private function localAlbumsForArtist(Artist $artist): EloquentCollection
    {
        if (! array_key_exists($artist->id, $this->localArtistAlbums)) {
            $this->localArtistAlbums[$artist->id] = Album::query()
                ->where('artist_id', $artist->id)
                ->get();
        }

        return $this->localArtistAlbums[$artist->id];
    }

    private function rememberLocalAlbum(Album $album, ?int $previousArtistId = null): void
    {
        if ($previousArtistId !== null && $previousArtistId !== (int) $album->artist_id) {
            unset($this->localArtistAlbums[$previousArtistId]);
        }

        unset($this->localArtistAlbums[(int) $album->artist_id]);
    }

    private function findLocalAlbumByComparableTitle(Artist $artist, string $albumTitle): ?Album
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedStoredAlbumTitle = Str::lower($storedAlbumTitle);
        $normalizedLookupKey = $this->albumComparableLookupKey($storedAlbumTitle);
        $prefersOriginalAlbum = $this->versionFlags($storedAlbumTitle) === [];
        $candidates = [];

        foreach ($this->localAlbumsForArtist($artist) as $album) {
            $candidateTitle = $this->storedAlbumTitle((string) $album->title);

            if ($candidateTitle === '') {
                continue;
            }

            $exactMatch = Str::lower($candidateTitle) === $normalizedStoredAlbumTitle;
            $lookupMatch = $normalizedLookupKey !== '' && $normalizedLookupKey === $this->albumComparableLookupKey($candidateTitle);

            if (! $exactMatch && ! $lookupMatch) {
                continue;
            }

            $candidates[] = [
                'album' => $album,
                'exact' => $exactMatch,
                'non_original' => $this->hasNonOriginalAlbumMarkers($candidateTitle),
                'demo' => $this->hasDemoMarkers($candidateTitle),
                'compilation' => $this->hasCompilationMarkers($candidateTitle),
                'prefer_original' => $prefersOriginalAlbum,
                'release_date' => $album->release_date?->format('Y-m-d'),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if (($left['exact'] ?? false) !== ($right['exact'] ?? false)) {
                return ($left['exact'] ?? false) ? -1 : 1;
            }

            if ((bool) ($left['prefer_original'] ?? false)) {
                if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
                    return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
                }

                if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                    return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
                }

                if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                    return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
                }
            }

            $leftReleaseDate = (string) ($left['release_date'] ?? '9999-12-31');
            $rightReleaseDate = (string) ($right['release_date'] ?? '9999-12-31');
            $releaseDateCompare = $leftReleaseDate <=> $rightReleaseDate;

            if ($releaseDateCompare !== 0) {
                return $releaseDateCompare;
            }

            return ((int) ($left['album']->id ?? 0)) <=> ((int) ($right['album']->id ?? 0));
        });

        $bestCandidate = $candidates[0] ?? null;

        return $bestCandidate['album'] ?? null;
    }

    private function refreshExistingAlbumFromGenius(Album $album, Artist $fallbackArtist, array $payload): void
    {
        $previousArtistId = $album->artist_id;
        $albumArtistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($albumArtistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $albumArtistPayloads = collect([$payload['artist']]);
        }

        $albumArtists = new EloquentCollection();

        foreach ($albumArtistPayloads as $index => $artistPayload) {
            [$albumArtist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);

            if ($this->isVariousArtistsName($albumArtist->name)) {
                continue;
            }

            $albumArtists->push($albumArtist);
        }

        if ($albumArtists->isEmpty()) {
            if (method_exists($album, 'artists')) {
                $album->loadMissing('artists');

                if ($album->artists->isNotEmpty()) {
                    $albumArtists = new EloquentCollection($album->artists->all());
                }
            }

            if ($albumArtists->isEmpty()) {
                $albumArtists->push($fallbackArtist);
            }
        }

        $albumArtists = $this->rejectVariousArtists(new EloquentCollection(
            $albumArtists
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));
        /** @var Artist $primaryAlbumArtist */
        $primaryAlbumArtist = $this->resolvePreferredAlbumArtist($fallbackArtist, $albumArtists);

        $incomingGeniusId = (int) ($payload['id'] ?? 0);
        $safeGeniusId = $album->genius_id;

        if ($incomingGeniusId > 0) {
            $conflictingAlbum = Album::query()
                ->where('genius_id', $incomingGeniusId)
                ->whereKeyNot($album->id)
                ->first();

            if (! $conflictingAlbum instanceof Album) {
                $safeGeniusId = $incomingGeniusId;
            }
        }

        $incomingTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        $albumPayload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryAlbumArtist->id,
            'title' => $this->shouldReplaceAlbumTitleFromGenius($album, $incomingTitle)
                ? $incomingTitle
                : $album->title,
            'release_date' => $payload['release_date'] ?? $album->release_date,
            'cover_image_url' => $payload['cover_art_thumbnail_url']
                ?? $payload['cover_art_url']
                ?? $payload['image_url']
                ?? $album->cover_image_url,
            'genius_id' => $safeGeniusId,
        ]);

        try {
            $album->forceFill($albumPayload)->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception, 'albums_genius_id_unique') || (int) ($albumPayload['genius_id'] ?? 0) <= 0) {
                throw $exception;
            }

            $existingAlbum = Album::query()
                ->where('genius_id', (int) $albumPayload['genius_id'])
                ->first();

            if (! $existingAlbum instanceof Album) {
                throw $exception;
            }

            $album = $existingAlbum;
            $album->forceFill($albumPayload)->save();
        }

        $this->rememberLocalAlbum($album, $previousArtistId);

        if (method_exists($album, 'artists') && $albumArtists->isNotEmpty()) {
            $album->artists()->sync($albumArtists->pluck('id')->unique()->values()->all());
        }
    }

    private function shouldReplaceAlbumTitleFromGenius(Album $album, string $incomingTitle): bool
    {
        if ($incomingTitle === '') {
            return false;
        }

        $currentRawTitle = (string) ($album->title ?? '');
        $currentTitle = $this->storedAlbumTitle($currentRawTitle);

        if ($currentTitle === '') {
            return true;
        }

        if ($currentTitle === $incomingTitle && $currentRawTitle !== $incomingTitle) {
            return true;
        }

        if ($currentTitle === $incomingTitle) {
            return false;
        }

        if ($this->albumTitleReadabilityScore($incomingTitle) > ($this->albumTitleReadabilityScore($currentTitle) + 0.12)) {
            return true;
        }

        return $this->albumTitleSimilarity($currentTitle, $incomingTitle) >= 0.92
            && $this->hasNonOriginalAlbumMarkers($currentTitle) === $this->hasNonOriginalAlbumMarkers($incomingTitle);
    }

    private function albumTitlesShareIdentity(string $left, string $right): bool
    {
        $left = $this->storedAlbumTitle($left);
        $right = $this->storedAlbumTitle($right);

        if ($left === '' || $right === '') {
            return false;
        }

        if (Str::lower($left) === Str::lower($right)) {
            return true;
        }

        if ($this->albumComparableLookupKey($left) !== '' && $this->albumComparableLookupKey($left) === $this->albumComparableLookupKey($right)) {
            return true;
        }

        return $this->albumDigitSignatureScore($left, $right) >= 0.84;
    }

    private function resolvePreferredAlbumArtist(Artist $fallbackArtist, EloquentCollection $albumArtists): Artist
    {
        /** @var Artist|null $firstAlbumArtist */
        $firstAlbumArtist = $albumArtists->first();

        if ($firstAlbumArtist && ! $this->isVariousArtistsName($firstAlbumArtist->name)) {
            return $firstAlbumArtist;
        }

        return $fallbackArtist;
    }

    private function resolveTrackPrimaryArtist(
        Artist $fallbackArtist,
        EloquentCollection $primaryPerformers,
        EloquentCollection $performers,
        ParsedTrack $parsedTrack,
    ): Artist {
        foreach ($primaryPerformers as $primaryPerformer) {
            if ($primaryPerformer instanceof Artist && $this->artistMatchesFallback($primaryPerformer, $fallbackArtist)) {
                return $primaryPerformer;
            }
        }

        $bestArtist = null;
        $bestScore = 0.0;

        foreach ($primaryPerformers as $primaryPerformer) {
            if (! $primaryPerformer instanceof Artist) {
                continue;
            }

            $score = max(
                GeniusNameMatcher::bestArtistScore($fallbackArtist->name, [$primaryPerformer->name]),
                GeniusNameMatcher::bestArtistScore($primaryPerformer->name, $parsedTrack->artistNames),
            );

            if ($score > $bestScore) {
                $bestArtist = $primaryPerformer;
                $bestScore = $score;
            }
        }

        if ($bestArtist instanceof Artist && $bestScore >= 0.82) {
            return $bestArtist;
        }

        /** @var Artist|null $firstPrimary */
        $firstPrimary = $primaryPerformers->first();

        if ($firstPrimary instanceof Artist && ! $this->isVariousArtistsName($firstPrimary->name)) {
            return $firstPrimary;
        }

        if ($performers->contains(fn (Artist $artist) => $this->artistMatchesFallback($artist, $fallbackArtist))) {
            return $fallbackArtist;
        }

        return $fallbackArtist;
    }

    private function resolveTrackNumber(ParsedTrack $parsedTrack, array $songPayload): ?int
    {
        $songTrackNumber = $this->extractSongTrackNumber($songPayload);

        if ($songTrackNumber !== null) {
            return $songTrackNumber;
        }

        return $parsedTrack->trackNumber;
    }

    private function artistMatchesFallback(Artist $candidate, Artist $fallbackArtist): bool
    {
        if ($candidate->id === $fallbackArtist->id) {
            return true;
        }

        if ($candidate->genius_id && $fallbackArtist->genius_id && (int) $candidate->genius_id === (int) $fallbackArtist->genius_id) {
            return true;
        }

        return GeniusNameMatcher::bestArtistScore($fallbackArtist->name, [$candidate->name]) >= 0.82;
    }

    private function isVariousArtistsName(?string $name): bool
    {
        return in_array(GeniusNameMatcher::normalizeLoose((string) ($name ?? '')), [
            'various artists',
            'various',
            'raznye ispolniteli',
            'sbornik',
        ], true);
    }

    private function resolveReleaseYear(array $songPayload, ParsedTrack $parsedTrack): ?int
    {
        if (! empty($songPayload['release_date_components']['year'])) {
            return (int) $songPayload['release_date_components']['year'];
        }

        if (! empty($songPayload['release_date']) && preg_match('/\b(19|20)\d{2}\b/', (string) $songPayload['release_date'], $matches) === 1) {
            return (int) $matches[0];
        }

        return $parsedTrack->releaseYear;
    }

    private function releaseSortValue(array $payload): int
    {
        $year = (int) data_get($payload, 'release_date_components.year', 0);
        $month = (int) data_get($payload, 'release_date_components.month', 0);
        $day = (int) data_get($payload, 'release_date_components.day', 0);

        if ($year > 0) {
            return ($year * 10000) + (max(1, $month) * 100) + max(1, $day);
        }

        $releaseDate = (string) ($payload['release_date'] ?? data_get($payload, 'album.release_date', ''));

        if ($releaseDate !== '' && preg_match('/\b(?<year>(?:19|20)\d{2})(?:-(?<month>\d{1,2}))?(?:-(?<day>\d{1,2}))?\b/', $releaseDate, $matches) === 1) {
            return ((int) ($matches['year'] ?? 0) * 10000)
                + (max(1, (int) ($matches['month'] ?? 1)) * 100)
                + max(1, (int) ($matches['day'] ?? 1));
        }

        return PHP_INT_MAX;
    }

    /**
     * @return Artist[]
     */
    private function resolveFallbackCreditedArtists(ParsedArtistPage $page, ParsedTrack $parsedTrack, Artist $primaryArtist): array
    {
        $artists = [$primaryArtist];
        $pageArtistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        foreach ($parsedTrack->artistNames as $artistName) {
            foreach (GeniusNameMatcher::splitArtistCredits($artistName, $page->artistName) as $creditedArtistName) {
                $normalizedName = GeniusNameMatcher::normalizeLoose($creditedArtistName);

                if ($normalizedName === '' || $this->bestReferenceArtistScore($pageArtistReferenceNames, [$creditedArtistName]) >= 0.92) {
                    continue;
                }

                $artists[] = $this->upsertArtistByName($creditedArtistName);
            }
        }

        return collect($artists)
            ->unique(fn (Artist $artist) => $artist->id)
            ->values()
            ->all();
    }

    private function upsertArtistByName(string $name): Artist
    {
        $storedName = GeniusNameMatcher::storageValue($name);

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
            ->first();

        if ($artist) {
            return $artist;
        }

        $baseSlug = Str::slug($storedName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'artist';

        return Artist::create(GeniusNameMatcher::sanitizeForDatabase([
            'name' => $storedName,
            'slug' => $this->uniqueArtistSlug($baseSlug),
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]));
    }

    private function resolveFallbackAlbumId(Artist $primaryArtist, ParsedTrack $parsedTrack): ?int
    {
        if (! $this->shouldAttachFallbackTrackToAlbum($parsedTrack)) {
            return null;
        }

        $albumTitle = $this->storedAlbumTitle((string) ($parsedTrack->albumTitle ?? ''));

        if ($albumTitle === '') {
            return null;
        }

        $album = $this->findBestLocalFallbackAlbumMatch($primaryArtist, $albumTitle);

        if ($album instanceof Album) {
            $this->refreshExistingAlbumTitleFromParsedTrack($album, $albumTitle);

            return $album->id;
        }

        return null;
    }

    private function refreshExistingAlbumTitleFromParsedTrack(Album $album, string $albumTitle): void
    {
        $candidateTitle = $this->storedAlbumTitle($albumTitle);
        $currentTitle = (string) ($album->title ?? '');

        if ($candidateTitle === '' || $candidateTitle === $currentTitle) {
            return;
        }

        if ($this->albumTitleReadabilityScore($candidateTitle) <= ($this->albumTitleReadabilityScore($currentTitle) + 0.12)) {
            return;
        }

        $album->forceFill([
            'title' => $candidateTitle,
        ])->save();
    }

    private function albumTitleReadabilityScore(string $title): float
    {
        $title = trim($title);

        if ($title === '') {
            return 0.0;
        }

        $letters = preg_match_all('/\p{L}/u', $title);
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $title);
        $latin = preg_match_all('/\p{Latin}/u', $title);
        $digits = preg_match_all('/\d/u', $title);
        $broken = preg_match_all('/(?:Р[А-Яа-яЁёA-Za-z]|С[А-Яа-яЁёA-Za-z]|Ã.|Â.|â.|Ð.|Ñ.|вЂ|в€)/u', $title);
        $meaningful = max(1, (int) $letters + (int) $digits);
        $score = (((int) $cyrillic + (int) $latin + (int) $digits) / $meaningful)
            + ((int) $cyrillic > 0 ? 0.35 : 0.0)
            + ((int) $latin > 0 ? 0.08 : 0.0)
            - ((int) $broken * 0.08);

        if ($this->storedAlbumTitle($title) === $title) {
            $score += 0.08;
        }

        return round($score, 4);
    }

    private function shouldAttachFallbackTrackToAlbum(ParsedTrack $parsedTrack): bool
    {
        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && ! in_array('ringtone', $this->versionFlags($parsedTrack->title), true)
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle);
    }

    private function findBestLocalFallbackAlbumMatch(Artist $primaryArtist, string $albumTitle): ?Album
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedStoredAlbumTitle = Str::lower($storedAlbumTitle);
        $comparisonTitle = $this->albumComparableLookupKey($storedAlbumTitle);
        $prefersOriginalAlbum = $this->versionFlags($storedAlbumTitle) === [];
        $candidates = [];

        foreach ($this->localAlbumsForArtist($primaryArtist) as $album) {
            $candidateTitle = $this->storedAlbumTitle((string) $album->title);

            if ($candidateTitle === '') {
                continue;
            }

            $candidateLookupKey = $this->albumComparableLookupKey($candidateTitle);
            $exactMatch = Str::lower($candidateTitle) === $normalizedStoredAlbumTitle
                || ($comparisonTitle !== '' && $comparisonTitle === $candidateLookupKey);
            $identityMatch = $exactMatch || $this->albumTitlesShareIdentity($storedAlbumTitle, $candidateTitle);
            $baseScore = $this->albumTitleSimilarity($storedAlbumTitle, $candidateTitle);

            if (! $identityMatch) {
                continue;
            }

            $versionAdjustment = $this->versionMatchAdjustment($storedAlbumTitle, $candidateTitle);

            if ($baseScore >= 0.9 && $versionAdjustment < -0.04) {
                $versionAdjustment = -0.04;
            }

            $score = $exactMatch ? 1.4 : ($baseScore + $versionAdjustment);

            $nonOriginal = $this->hasNonOriginalAlbumMarkers($candidateTitle);
            $demo = $this->hasDemoMarkers($candidateTitle);
            $compilation = $this->hasCompilationMarkers($candidateTitle);

            if ($prefersOriginalAlbum && $nonOriginal) {
                $score -= 0.28;
            }

            if ($prefersOriginalAlbum && $demo) {
                $score -= 0.12;
            }

            if ($prefersOriginalAlbum && $compilation) {
                $score -= 0.08;
            }

            if ($prefersOriginalAlbum && ! $exactMatch && $nonOriginal) {
                continue;
            }

            $candidates[] = [
                'album' => $album,
                'exact' => $exactMatch,
                'identity' => $identityMatch,
                'score' => $score,
                'non_original' => $nonOriginal,
                'demo' => $demo,
                'compilation' => $compilation,
                'prefer_original' => $prefersOriginalAlbum,
                'has_genius' => $album->genius_id !== null,
                'release_date' => $album->release_date?->format('Y-m-d'),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if (($left['exact'] ?? false) !== ($right['exact'] ?? false)) {
                return ($left['exact'] ?? false) ? -1 : 1;
            }

            if ((bool) ($left['prefer_original'] ?? false)) {
                if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
                    return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
                }

                if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                    return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
                }

                if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                    return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
                }
            }

            $leftReleaseDate = (string) ($left['release_date'] ?? '9999-12-31');
            $rightReleaseDate = (string) ($right['release_date'] ?? '9999-12-31');
            $releaseDateCompare = $leftReleaseDate <=> $rightReleaseDate;

            if ($releaseDateCompare !== 0) {
                return $releaseDateCompare;
            }

            $scoreCompare = (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0);

            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            if (($left['has_genius'] ?? false) !== ($right['has_genius'] ?? false)) {
                return ($left['has_genius'] ?? false) ? -1 : 1;
            }

            return ((int) ($left['album']->id ?? 0)) <=> ((int) ($right['album']->id ?? 0));
        });

        $bestCandidate = $candidates[0] ?? null;

        if (! is_array($bestCandidate) || ! ($bestCandidate['album'] ?? null) instanceof Album) {
            return null;
        }

        if (! ($bestCandidate['identity'] ?? false)) {
            return null;
        }

        return $bestCandidate['album'];
    }

    private function albumComparableLookupKey(string $title): string
    {
        $storedTitle = $this->storedAlbumTitle($title);
        $normalized = $this->normalizeAlbumComparisonTitle($storedTitle);

        if ($normalized !== '') {
            return $normalized;
        }

        return GeniusNameMatcher::normalizeLoose($storedTitle);
    }

    private function storedAlbumTitle(?string $title): string
    {
        return GeniusNameMatcher::albumStorageValue((string) ($title ?? ''));
    }

    private function albumTitleSimilarity(string $left, string $right): float
    {
        $left = $this->storedAlbumTitle($left);
        $right = $this->storedAlbumTitle($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        return max(
            GeniusNameMatcher::score($left, $right),
            GeniusNameMatcher::score(
                $this->albumComparableLookupKey($left),
                $this->albumComparableLookupKey($right),
            ),
            $this->albumDigitSignatureScore($left, $right),
            $this->symbolicAlbumMatchScore($left, $right),
        );
    }

    private function albumDigitSignatureScore(string $left, string $right): float
    {
        $leftDigits = $this->albumDigitSignature($left);
        $rightDigits = $this->albumDigitSignature($right);

        if ($leftDigits === '' || $rightDigits === '' || $leftDigits !== $rightDigits || strlen($leftDigits) < 3) {
            return 0.0;
        }

        $rawScore = GeniusNameMatcher::score($left, $right);

        if ($rawScore < 0.42) {
            return 0.0;
        }

        $leftFlags = $this->versionFlags($left);
        $rightFlags = $this->versionFlags($right);

        if ($leftFlags !== [] && $rightFlags !== [] && array_intersect($leftFlags, $rightFlags) === []) {
            return 0.0;
        }

        return $leftFlags !== [] || $rightFlags !== [] ? 0.9 : 0.84;
    }

    private function albumDigitSignature(string $title): string
    {
        preg_match_all('/\d+/u', $this->storedAlbumTitle($title), $matches);

        return implode('', $matches[0] ?? []);
    }

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbumFallback(Artist $primaryArtist, string $albumTitle): array
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedTitle = Str::lower($storedAlbumTitle);

        $album = Album::query()
            ->where('artist_id', $primaryArtist->id)
            ->whereRaw('LOWER(title) = ?', [$normalizedTitle])
            ->first();

        $created = false;

        if (! $album) {
            $album = Album::create(GeniusNameMatcher::sanitizeForDatabase([
                'artist_id' => $primaryArtist->id,
                'title' => $storedAlbumTitle,
                'slug' => $this->uniqueAlbumSlug(Str::slug($storedAlbumTitle) ?: 'album'),
                'cover_image_url' => null,
                'release_date' => null,
                'genius_id' => null,
            ]));

            $created = true;
        }

        if (method_exists($album, 'artists')) {
            $album->artists()->syncWithoutDetaching([$primaryArtist->id]);
        }

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrackFallback(Artist $primaryArtist, ?int $albumId, ParsedTrack $parsedTrack): array
    {
        $track = Track::query()
            ->where(function ($query) use ($parsedTrack): void {
                $query->where('audio_url', $parsedTrack->audioUrl);

                if ($this->trackHasColumn('original_link')) {
                    $query->orWhere('original_link', $parsedTrack->audioUrl);
                }
            })
            ->first();

        if (! $track) {
            $track = Track::query()
                ->where('artist_id', $primaryArtist->id)
                ->where('album_id', $albumId)
                ->whereRaw('LOWER(title) = ?', [Str::lower(GeniusNameMatcher::storageValue($parsedTrack->title))])
                ->where('duration_seconds', $parsedTrack->durationSeconds)
                ->first();
        }

        $created = false;

        if (! $track) {
            $created = true;
            $track = new Track();
        }

        $payload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => GeniusNameMatcher::storageValue($parsedTrack->title),
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
            'release_year' => $parsedTrack->releaseYear,
            'track_number' => $parsedTrack->trackNumber,
            'genres' => $parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null,
            'genius_pageviews' => 0,
        ]);

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        if ($this->trackHasColumn('audio_hash')) {
            $payload['audio_hash'] = sha1((string) $parsedTrack->audioUrl);
        }

        if (! $this->trackHasColumn('genius_pageviews')) {
            unset($payload['genius_pageviews']);
        }

        $track->forceFill($payload)->save();

        return [$track, $created];
    }


    private function shouldPersistAlbumTitle(?string $albumTitle): bool
    {
        $storedAlbumTitle = $this->storedAlbumTitle((string) ($albumTitle ?? ''));

        if ($storedAlbumTitle === '') {
            return false;
        }

        $normalized = GeniusNameMatcher::normalizeLoose($storedAlbumTitle);

        if ($normalized === '') {
            return preg_match('/[\p{L}\p{N}\p{S}]/u', $storedAlbumTitle) === 1;
        }

        return ! in_array($normalized, [
            'novinki',
            'novinka',
            'new',
            'news',
            'popularnoe',
            'populiarnoe',
            'top',
            'bonustrack',
            'bonus track',
        ], true);
    }

    private function songAlbumMatchesParsedTrack(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return true;
        }

        $songAlbumTitle = $this->extractSongAlbumTitle($songPayload);

        if ($songAlbumTitle === null) {
            return true;
        }

        return $this->albumMatchAdjustment(
            $parsedTrack->albumTitle,
            $songAlbumTitle,
            $parsedTrack->title,
            (string) ($songPayload['title'] ?? ''),
        ) > -0.16;
    }

    private function extractSongAlbumTitle(array $songPayload): ?string
    {
        foreach (['album.name', 'album.title', 'album.full_title', 'album.display_title', 'release.name', 'release.title'] as $path) {
            $value = data_get($songPayload, $path);

            if (! is_string($value)) {
                continue;
            }

            $value = $this->storedAlbumTitle($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractAlbumPayloadTitle(array $albumPayload): ?string
    {
        foreach (['name', 'title', 'full_title', 'display_title'] as $path) {
            $value = data_get($albumPayload, $path);

            if (! is_string($value)) {
                continue;
            }

            $value = $this->storedAlbumTitle($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function compilationAlbumPenalty(string $muzofondAlbumTitle, string $geniusAlbumTitle): float
    {
        $normalizedMuzofond = $this->normalizeAlbumComparisonTitle($muzofondAlbumTitle);
        $normalizedGenius = $this->normalizeAlbumComparisonTitle($geniusAlbumTitle);

        if ($normalizedMuzofond === '' || $normalizedGenius === '' || $normalizedMuzofond === $normalizedGenius) {
            return 0.0;
        }

        $muzofondHasCompilationMarkers = $this->hasCompilationMarkers($muzofondAlbumTitle);
        $geniusHasCompilationMarkers = $this->hasCompilationMarkers($geniusAlbumTitle);
        $muzofondHasDemoMarkers = $this->hasDemoMarkers($muzofondAlbumTitle);
        $geniusHasDemoMarkers = $this->hasDemoMarkers($geniusAlbumTitle);
        $muzofondVersionFlags = $this->versionFlags($muzofondAlbumTitle);
        $geniusVersionFlags = $this->versionFlags($geniusAlbumTitle);
        $penalty = 0.0;

        if (! $muzofondHasCompilationMarkers && $geniusHasCompilationMarkers) {
            $penalty -= 0.24;
        }

        if (! $muzofondHasDemoMarkers && $geniusHasDemoMarkers) {
            $penalty -= 0.18;
        }

        if ($muzofondVersionFlags === []
            && array_intersect($geniusVersionFlags, ['live', 'remix', 'remixes', 'instrumental', 'karaoke', 'acoustic', 'edit', 'remaster', 'ringtone']) !== []) {
            $penalty -= 0.42;
        }

        if ($muzofondVersionFlags === []
            && array_intersect($geniusVersionFlags, ['deluxe', 'ver2']) !== []) {
            $penalty -= 0.24;
        }

        if ($muzofondVersionFlags !== []
            && $geniusVersionFlags !== []
            && array_intersect($muzofondVersionFlags, $geniusVersionFlags) === []) {
            $penalty -= 0.18;
        }

        if ((str_contains($normalizedGenius, $normalizedMuzofond) || str_contains($normalizedMuzofond, $normalizedGenius))
            && $this->versionFlags($muzofondAlbumTitle) === []
            && $this->versionFlags($geniusAlbumTitle) === []) {
            $penalty -= 0.18;
        }

        return $penalty;
    }

    private function hasCompilationMarkers(string $title): bool
    {
        $normalized = GeniusNameMatcher::normalizeLoose($title);

        return preg_match('/\b(?:best of|greatest hits|hits|anthology|collection|collected|essentials|complete|selected|singles|compilation|box set|chapters|chronicles)\b/iu', $normalized) === 1;
    }

    private function hasDemoMarkers(string $title): bool
    {
        return preg_match('/\bdemo\b/iu', GeniusNameMatcher::normalizeLoose($title)) === 1;
    }

    private function hasSingleLikeAlbumMarkers(string $title, ?string $trackTitle = null): bool
    {
        $storedTitle = $this->storedAlbumTitle($title);

        if ($storedTitle === '') {
            return false;
        }

        $normalized = GeniusNameMatcher::normalizeLoose($storedTitle);

        if (preg_match('/\b(?:single|ep)\b/iu', $normalized) === 1) {
            return true;
        }

        if ($trackTitle === null || $trackTitle === '') {
            return false;
        }

        return GeniusNameMatcher::score(
            GeniusNameMatcher::canonicalTrack($storedTitle),
            GeniusNameMatcher::canonicalTrack($trackTitle),
        ) >= 0.96;
    }

    private function hasNonOriginalAlbumMarkers(string $title): bool
    {
        return array_intersect($this->versionFlags($title), [
            'live',
            'remix',
            'remixes',
            'instrumental',
            'karaoke',
            'demo',
            'acoustic',
            'edit',
            'deluxe',
            'ver2',
            'remaster',
            'ringtone',
        ]) !== [];
    }

    private function releaseYearMatchAdjustment(?int $muzofondReleaseYear, array $payload): float
    {
        if (! $muzofondReleaseYear) {
            return 0.0;
        }

        $geniusReleaseYear = (int) data_get($payload, 'release_date_components.year', 0);

        if ($geniusReleaseYear <= 0 && preg_match('/\b(19|20)\d{2}\b/', (string) ($payload['release_date'] ?? ''), $matches) === 1) {
            $geniusReleaseYear = (int) $matches[0];
        }

        if ($geniusReleaseYear <= 0 && preg_match('/\b(19|20)\d{2}\b/', (string) data_get($payload, 'album.release_date', ''), $matches) === 1) {
            $geniusReleaseYear = (int) $matches[0];
        }

        if ($geniusReleaseYear <= 0) {
            return 0.0;
        }

        $delta = abs($muzofondReleaseYear - $geniusReleaseYear);

        return match (true) {
            $delta === 0 => 0.12,
            $delta === 1 => 0.06,
            $delta === 2 => -0.03,
            $delta <= 4 => -0.12,
            default => -0.24,
        };
    }

    private function albumMatchAdjustment(?string $muzofondAlbumTitle, ?string $geniusAlbumTitle, string $muzofondTitle, string $geniusTitle): float
    {
        if (! $this->shouldPersistAlbumTitle($muzofondAlbumTitle)) {
            return 0.0;
        }

        $muzofondAlbumTitle = $this->storedAlbumTitle((string) $muzofondAlbumTitle);
        $geniusAlbumTitle = $this->storedAlbumTitle((string) ($geniusAlbumTitle ?? ''));

        if ($muzofondAlbumTitle === '') {
            return 0.0;
        }

        if ($geniusAlbumTitle === '') {
            return -0.08;
        }

        $baseScore = $this->albumTitleSimilarity($muzofondAlbumTitle, $geniusAlbumTitle);

        $versionScore = $this->versionMatchAdjustment(
            $muzofondAlbumTitle . ' ' . $muzofondTitle,
            $geniusAlbumTitle . ' ' . $geniusTitle,
        );
        $compilationPenalty = $this->compilationAlbumPenalty($muzofondAlbumTitle, $geniusAlbumTitle);

        if ($baseScore >= 0.96) {
            return 0.22 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.88) {
            return 0.14 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.76) {
            return 0.02 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.62) {
            return -0.10 + $versionScore + $compilationPenalty;
        }

        return -0.28 + $versionScore + $compilationPenalty;
    }

    private function normalizeAlbumComparisonTitle(string $value): string
    {
        $value = $this->unwrapLeadingSymbolicAlbumPrefix($this->storedAlbumTitle($value));
        $value = preg_replace('/\b(?:live|remix(?:es)?|mix(?:es)?|instrumental|karaoke|demo|acoustic|radio\s+edit|edit|deluxe|expanded|bonus|ep|single|version|edition|remaster(?:ed)?|ring(?: ?tone)?|caller\s+tune)\b/iu', ' ', $value) ?? $value;
        $value = str_replace(['(', ')', '[', ']', '{', '}'], ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
        $normalized = GeniusNameMatcher::normalizeLoose($value);

        if ($normalized !== '') {
            return $normalized;
        }

        return $this->normalizeSymbolicAlbumTitle($value);
    }

    private function normalizeSymbolicAlbumTitle(string $value): string
    {
        $value = $this->unwrapLeadingSymbolicAlbumPrefix($this->storedAlbumTitle($value));
        $value = Str::lower($value);
        $value = str_replace(['ё'], ['е'], $value);
        $value = preg_replace('/[^\p{L}\p{N}\p{S}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function unwrapLeadingSymbolicAlbumPrefix(string $value): string
    {
        $value = $this->storedAlbumTitle($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(?<prefix>[^()]+?)\s*\((?<inner>.+)\)$/u', $value, $matches) !== 1) {
            return $value;
        }

        $prefix = trim((string) ($matches['prefix'] ?? ''));
        $inner = trim((string) ($matches['inner'] ?? ''));

        if ($prefix === '' || $inner === '' || preg_match('/[\p{L}\p{N}]/u', $inner) !== 1) {
            return $value;
        }

        if ($this->looksLikeBrokenAlbumSymbolPrefix($prefix) || preg_match('/^\p{S}+$/u', $prefix) === 1) {
            return $inner;
        }

        return $value;
    }

    private function looksLikeBrokenAlbumSymbolPrefix(string $value): bool
    {
        $normalized = GeniusNameMatcher::normalizeLoose($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['riv s', 'ri v s', 'rv s', 'v s', 'ri v', 'riv'], true)) {
            return true;
        }

        return preg_match('/^(?:r|ri|riv|v|s)(?:\s+(?:r|ri|riv|v|s))*$/u', $normalized) === 1;
    }

    private function symbolicAlbumMatchScore(string $muzofondAlbumTitle, string $geniusAlbumTitle): float
    {
        $muzofondSymbols = $this->meaningfulAlbumSymbols($muzofondAlbumTitle);
        $geniusSymbols = $this->meaningfulAlbumSymbols($geniusAlbumTitle);

        if ($muzofondSymbols === [] || $geniusSymbols === []) {
            return 0.0;
        }

        return array_intersect($muzofondSymbols, $geniusSymbols) !== [] ? 0.98 : 0.0;
    }

    /**
     * @return string[]
     */
    private function meaningfulAlbumSymbols(string $value): array
    {
        $value = $this->storedAlbumTitle($value);

        if ($value === '' || preg_match_all('/\p{S}+/u', $value, $matches) !== 1) {
            return [];
        }

        return collect($matches[0] ?? [])
            ->map(fn (string $symbol) => trim($symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function versionMatchAdjustment(string $muzofondTitle, string $geniusTitle): float
    {
        $muzofondFlags = $this->versionFlags($muzofondTitle);
        $geniusFlags = $this->versionFlags($geniusTitle);
        $muzofondHasOnlyDemo = in_array('demo', $muzofondFlags, true)
            && array_values(array_diff($muzofondFlags, ['demo'])) === [];
        $geniusHasOnlyDemo = in_array('demo', $geniusFlags, true)
            && array_values(array_diff($geniusFlags, ['demo'])) === [];

        if ($muzofondFlags === [] && $geniusFlags === []) {
            return 0.0;
        }

        if ($muzofondHasOnlyDemo && $geniusFlags === []) {
            return 0.08;
        }

        if ($muzofondFlags === [] && $geniusHasOnlyDemo) {
            return -0.12;
        }

        if ($muzofondHasOnlyDemo && $geniusHasOnlyDemo) {
            return -0.02;
        }

        if ($muzofondFlags === [] && $geniusFlags !== []) {
            return array_intersect($geniusFlags, ['instrumental', 'karaoke', 'live', 'remix', 'remixes', 'ep', 'edit', 'ringtone']) !== []
                ? -0.22
                : -0.08;
        }

        if ($muzofondFlags !== [] && $geniusFlags === []) {
            return array_intersect($muzofondFlags, ['ringtone']) !== []
                ? -0.28
                : -0.14;
        }

        $intersection = array_values(array_intersect($muzofondFlags, $geniusFlags));

        if ($intersection === []) {
            return -0.24;
        }

        if (count($intersection) === count($muzofondFlags) && count($intersection) === count($geniusFlags)) {
            return 0.10;
        }

        return 0.02;
    }

    /**
     * @return string[]
     */
    private function versionFlags(string $title): array
    {
        $normalized = GeniusNameMatcher::normalizeLoose($title);
        $flags = [];

        $map = [
            'instrumental' => ['instrumental', 'instr', 'instrumentalnaia', 'instrumentalnaya', 'minus'],
            'remixes' => ['remixes'],
            'remix' => ['remix', 'rmx', 'dj mix', 'club mix', 'mix'],
            'live' => ['live', 'concert', 'concertnaia', 'concertnaya'],
            'demo' => ['demo'],
            'acoustic' => ['acoustic', 'akustika', 'acustic'],
            'karaoke' => ['karaoke'],
            'edit' => ['radio edit', 'edit'],
            'ep' => [' ep ', 'e p', 'extended play'],
            'deluxe' => ['deluxe', 'expanded', 'bonus edition'],
            'remaster' => ['remaster', 'remastered'],
            'ver2' => ['ver 2', 'ver2', 'version 2', 'version2', 'versiia 2', 'versiya 2'],
            'ringtone' => ['ringtone', 'ring tone', 'rington', 'rington', 'caller tune', 'na zvonok'],
        ];

        foreach ($map as $flag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains(' ' . $normalized . ' ', ' ' . $needle . ' ')) {
                    $flags[] = $flag;
                    break;
                }

                if ($needle !== trim($needle) && str_contains($normalized, trim($needle))) {
                    $flags[] = $flag;
                    break;
                }
            }
        }

        return array_values(array_unique($flags));
    }

    private function isUniqueConstraintViolation(QueryException $exception, string $constraint): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, $constraint)
            || (str_contains($message, 'duplicate key value violates unique constraint')
                && str_contains($message, '"' . $constraint . '"'));
    }

    private function cleanupEmptyAlbumsForArtist(Artist $artist): void
    {
        $query = Album::query()->doesntHave('tracks');

        if (method_exists(Album::class, 'artists')) {
            $query->where(function ($subQuery) use ($artist): void {
                $subQuery
                    ->where('artist_id', $artist->id)
                    ->orWhereHas('artists', fn ($artistQuery) => $artistQuery->whereKey($artist->id));
            });
        } else {
            $query->where('artist_id', $artist->id);
        }

        $query->delete();
    }

    private function uniqueArtistSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Artist::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function uniqueArtistSlugForExisting(string $baseSlug, ?int $ignoreArtistId = null): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Artist::query()
            ->when($ignoreArtistId, fn ($query) => $query->whereKeyNot($ignoreArtistId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function uniqueAlbumSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Album::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function trackHasColumn(string $column): bool
    {
        if ($this->trackColumns === null) {
            $this->trackColumns = Schema::getColumnListing((new Track())->getTable());
        }

        return in_array($column, $this->trackColumns, true);
    }

}
