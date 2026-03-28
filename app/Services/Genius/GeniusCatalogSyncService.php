<?php

namespace App\Services\Genius;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GeniusCatalogSyncService
{
    /**
     * @var string[]|null
     */
    private ?array $trackColumns = null;

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $songDetails = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $albumTrackSongCache = [];

    public function __construct(
        private readonly GeniusClient $geniusClient,
    ) {
    }

    /**
     * @return array{artist:Artist, artists:int, albums:int, tracks:int, matched_tracks:int, unmatched_tracks:int, genius_matched:bool}
     */
    public function syncArtistPage(ParsedArtistPage $page): array
    {
        [$artist, $artistCreated] = $this->upsertPrimaryArtistFromMuzofond($page);
        $artistsCreated = $artistCreated ? 1 : 0;
        $albumsCreated = 0;
        $tracksCreated = 0;
        $matchedTracks = 0;
        $unmatchedTracks = 0;

        $geniusArtist = $this->matchArtist($page);
        $usedSongIds = [];

        if (! $geniusArtist) {
            foreach ($page->tracks as $parsedTrack) {
                $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                [$track, $trackCreated] = $this->upsertTrackFallback($artist, null, $parsedTrack);
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
        $artistsCreated += $artistWasCreatedFromGenius ? 1 : 0;

        $albumSummaries = collect($this->geniusClient->allArtistAlbums((int) $geniusArtist['id']))
            ->filter(fn ($album) => is_array($album))
            ->values()
            ->all();

        $albumsCreated += $this->syncArtistAlbums($artist, $albumSummaries);

        $songSummaries = collect($this->geniusClient->allArtistSongs((int) $geniusArtist['id']))
            ->reject(function (array $song): bool {
                $title = GeniusNameMatcher::normalizeLoose((string) ($song['title'] ?? ''));

                return str_contains($title, 'romanized') || str_contains($title, 'translation');
            })
            ->values()
            ->all();

        foreach ($page->tracks as $parsedTrack) {
            try {
                $trackResult = DB::transaction(function () use ($page, $parsedTrack, $songSummaries, $albumSummaries, $artist, &$usedSongIds, &$albumsCreated): array {
                    $matchedSong = $this->findBestSongMatch($page, $parsedTrack, $songSummaries, $usedSongIds, true, $albumSummaries);

                    if (! $matchedSong) {
                        $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                        [$track, $trackCreated] = $this->upsertTrackFallback($artist, null, $parsedTrack);
                        $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());

                        return ['matched' => false, 'created' => $trackCreated];
                    }

                    [, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $matchedSong['detail']);
                    $usedSongIds[] = (int) ($matchedSong['summary']['id'] ?? 0);

                    return ['matched' => true, 'created' => $trackCreated];
                });

                if (! $trackResult['matched']) {
                    $unmatchedTracks++;
                    $tracksCreated += $trackResult['created'] ? 1 : 0;

                    continue;
                }

                $matchedTracks++;
                $tracksCreated += $trackResult['created'] ? 1 : 0;
            } catch (Throwable $exception) {
                $unmatchedTracks++;

                Log::warning('Genius sync skipped one track because of an exception.', [
                    'artist' => $page->artistName,
                    'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                    'exception' => $exception->getMessage(),
                ]);
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
            ? GeniusNameMatcher::storageValue((string) $parsedTrack->albumTitle)
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
        return $this->mergeSongSummaries(
            collect(GeniusNameMatcher::songSearchQueries($page->artistName, $parsedTrack->title, $parsedTrack->albumTitle))
            ->filter()
            ->unique()
            ->flatMap(fn (string $query) => $this->geniusClient->searchSongs($query))
            ->filter(fn ($song) => is_array($song))
            ->values()
            ->all()
        );
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

        foreach (array_slice($albumCandidates, 0, 4) as $albumCandidate) {
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

            if (count($albumTrackSongs) >= 18) {
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

        $parsedAlbumTitle = GeniusNameMatcher::storageValue((string) $parsedTrack->albumTitle);
        $parsedAlbumComparisonTitle = $this->normalizeAlbumComparisonTitle($parsedAlbumTitle);
        $candidates = [];

        foreach ($albumSummaries as $albumSummary) {
            if (! is_array($albumSummary) || empty($albumSummary['id'])) {
                continue;
            }

            $albumTitle = GeniusNameMatcher::storageValue((string) ($albumSummary['name'] ?? $albumSummary['title'] ?? ''));

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
                'release_sort' => $this->releaseSortValue($albumSummary),
                'score' => $albumSimilarity + $albumScore + $releaseYearScore + ($nonOriginalAlbum ? -0.12 : 0.04) + ($demo ? -0.08 : 0.02) + ($compilation ? -0.08 : 0.0),
            ];
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareAlbumTrackFallbackCandidates($left, $right));

        return $candidates;
    }

    private function compareAlbumTrackFallbackCandidates(array $left, array $right): int
    {
        $leftAlbumSimilarity = round((float) ($left['album_similarity'] ?? 0.0), 4);
        $rightAlbumSimilarity = round((float) ($right['album_similarity'] ?? 0.0), 4);

        if ($leftAlbumSimilarity !== $rightAlbumSimilarity) {
            return $rightAlbumSimilarity <=> $leftAlbumSimilarity;
        }

        $leftAlbumScore = round((float) ($left['album_score'] ?? 0.0), 4);
        $rightAlbumScore = round((float) ($right['album_score'] ?? 0.0), 4);

        if ($leftAlbumScore !== $rightAlbumScore) {
            return $rightAlbumScore <=> $leftAlbumScore;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

        if ($leftReleaseYearScore !== $rightReleaseYearScore) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        if (($left['non_original_album'] ?? false) !== ($right['non_original_album'] ?? false)) {
            return ((bool) ($left['non_original_album'] ?? false)) <=> ((bool) ($right['non_original_album'] ?? false));
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

        return round((float) ($right['score'] ?? 0.0), 4) <=> round((float) ($left['score'] ?? 0.0), 4);
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

        $songs = collect($this->geniusClient->albumTracks($albumId))
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

        foreach ($songSummaries as $songSummary) {
            $summaryTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

            if ($summaryTitle === '' || ! $this->songContainsArtist($songSummary, $page->artistName)) {
                continue;
            }

            $songId = (int) ($songSummary['id'] ?? 0);
            $songAlbumTitle = $this->extractSongAlbumTitle($songSummary);
            $titleScore = GeniusNameMatcher::score($parsedTitle, $summaryTitle);
            $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songSummary);
            $score = $titleScore + $versionScore + $albumScore + $releaseYearScore;
            $isAlbumTrackSummary = (string) ($songSummary['_wf_source'] ?? '') === 'album-track';

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
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'used' => in_array($songId, $usedSongIds, true),
                'exact_title' => $parsedTitle === $summaryTitle,
                'non_original_album' => $songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false,
                'demo' => $songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false,
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

        foreach (array_slice($candidates, 0, min($this->candidateResolutionLimit($parsedTrack), count($candidates))) as $candidate) {
            $songSummary = $candidate['song'] ?? null;

            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            $songDetail = $this->songDetail((int) $songSummary['id']);

            if (! is_array($songDetail)) {
                continue;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);

            if (! $this->songMatchesArtistPage($songDetail, $page->artistName)) {
                continue;
            }

            if (! $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            if (! $this->songAlbumMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            $songAlbumTitle = $this->extractSongAlbumTitle($songDetail);
            $detailTitle = GeniusNameMatcher::canonicalTrack((string) ($songDetail['title'] ?? ''));
            $titleScore = GeniusNameMatcher::score($parsedTitle, $detailTitle);
            $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songDetail['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songDetail['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songDetail);

            $resolvedCandidates[] = array_merge($candidate, [
                'summary' => $songSummary,
                'detail' => $songDetail,
                'score' => max((float) ($candidate['score'] ?? 0.0), $titleScore + $versionScore + $albumScore + $releaseYearScore),
                'title_score' => $titleScore,
                'title_priority' => $titleScore + $versionScore,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'exact_title' => $parsedTitle === $detailTitle,
                'non_original_album' => $songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false,
                'demo' => $songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false,
                'compilation' => $songAlbumTitle ? $this->hasCompilationMarkers($songAlbumTitle) : false,
                'release_sort' => $this->releaseSortValue($songDetail),
            ]);
        }

        return $resolvedCandidates;
    }

    private function candidateResolutionLimit(ParsedTrack $parsedTrack): int
    {
        if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return $this->versionFlags($parsedTrack->title) === [] ? 24 : 28;
        }

        return $this->versionFlags($parsedTrack->title) === [] ? 18 : 24;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function songDetail(int $songId): ?array
    {
        if (! array_key_exists($songId, $this->songDetails)) {
            $this->songDetails[$songId] = $this->geniusClient->song($songId);
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

        $leftAlbumPreference = (int) ($left['album_preference'] ?? 0);
        $rightAlbumPreference = (int) ($right['album_preference'] ?? 0);

        if ($leftExactTitle && $rightExactTitle && $leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        $leftTitlePriority = round((float) ($left['title_priority'] ?? 0.0), 4);
        $rightTitlePriority = round((float) ($right['title_priority'] ?? 0.0), 4);

        if ($leftTitlePriority !== $rightTitlePriority) {
            return $rightTitlePriority <=> $leftTitlePriority;
        }

        if ($leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

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

                $merged[$songId] = $incomingRichness >= $currentRichness
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

        Log::debug('Genius song match diagnostics.', [
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

        Log::debug('Genius album-track fallback diagnostics.', [
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
     * @return array<string, mixed>|null
     */
    private function matchArtist(ParsedArtistPage $page): ?array
    {
        $searchQueries = GeniusNameMatcher::artistSearchQueries($page->artistName);

        $candidates = collect($searchQueries)
            ->flatMap(fn (string $query) => $this->geniusClient->searchArtist($query))
            ->unique(fn (array $candidate) => (int) ($candidate['id'] ?? 0))
            ->take(12)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
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
            $artist = $this->geniusClient->artist((int) $candidate['id']);

            if (! $artist) {
                continue;
            }

            $aliases = array_merge(
                [(string) ($candidate['name'] ?? '')],
                [(string) ($artist['name'] ?? '')],
                array_values((array) ($artist['alternate_names'] ?? [])),
            );

            $nameScore = GeniusNameMatcher::bestArtistScore($page->artistName, $aliases);
            $overlap = $this->calculateSongOverlap((int) $artist['id'], $sampleTrackTitles);
            $exactAliasMatch = collect($aliases)->contains(fn (string $alias) => GeniusNameMatcher::bestArtistScore($page->artistName, [$alias]) >= 0.985);

            $score = $nameScore + ($exactAliasMatch ? 0.14 : 0.0);

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

        $songs = collect($this->geniusClient->allArtistSongs($artistId))
            ->take(120)
            ->map(fn (array $song) => GeniusNameMatcher::canonicalTrack((string) ($song['title'] ?? '')))
            ->filter()
            ->values();

        return collect($sampleTrackTitles)
            ->filter(fn (string $title) => $songs->contains($title))
            ->count();
    }

    private function songContainsArtist(array $songSummary, string $artistName): bool
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

                $artistNames = GeniusNameMatcher::splitArtistCredits($value, $artistName);

                if ($artistNames !== []) {
                    break;
                }
            }
        }

        return GeniusNameMatcher::bestArtistScore($artistName, $artistNames) >= 0.8;
    }

    private function songPrimaryArtistMatchesPage(array $songSummary, string $artistName): bool
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
            $primaryArtistNames = GeniusNameMatcher::splitArtistCredits((string) $songSummary['primary_artist_names'], $artistName);
        }

        return GeniusNameMatcher::bestArtistScore($artistName, $primaryArtistNames) >= 0.82;
    }

    private function songMatchesArtistPage(array $songSummary, string $artistName): bool
    {
        return $this->songPrimaryArtistMatchesPage($songSummary, $artistName)
            || $this->songContainsArtist($songSummary, $artistName);
    }

    private function songTitleMatchesParsedTrack(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($parsedTitle === '' || $songTitle === '') {
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
        $storedName = GeniusNameMatcher::storageValue($page->artistName);
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
        $created = 0;

        foreach ($albumSummaries as $albumSummary) {
            $albumDetail = $this->shouldCreateAlbumFromPayload($albumSummary)
                ? $albumSummary
                : ($this->geniusClient->album((int) $albumSummary['id']) ?? $albumSummary);

            if (! $this->shouldCreateAlbumFromPayload($albumDetail)) {
                continue;
            }

            [, $albumCreated] = $this->upsertAlbumFromGenius($artist, $albumDetail);
            $created += $albumCreated ? 1 : 0;
        }

        return $created;
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
        $albumTitle = GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $payload['title'] ?? ''));

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

        $album->forceFill($albumPayload)->save();

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

        $album = null;
        $albumId = null;

        if (is_array($songPayload['album'] ?? null) && isset($songPayload['album']['id'])) {
            $albumPayload = $this->geniusClient->album((int) $songPayload['album']['id']) ?? $songPayload['album'];

            if ($this->shouldCreateAlbumFromPayload($albumPayload)) {
                [$album] = $this->upsertAlbumFromGenius($fallbackArtist, $albumPayload);
                $albumId = $album->id;
            }
        }

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

        $track->forceFill($payload)->save();
        $track->artists()->sync($performers->pluck('id')->unique()->values()->all());

        return [$track, $created];
    }

    private function resolvePreferredAlbumArtist(Artist $fallbackArtist, EloquentCollection $albumArtists): Artist
    {
        foreach ($albumArtists as $albumArtist) {
            if ($albumArtist instanceof Artist && $this->artistMatchesFallback($albumArtist, $fallbackArtist)) {
                return $albumArtist;
            }
        }

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
        $albumId = (int) data_get($songPayload, 'album.id', 0);
        $songId = (int) ($songPayload['id'] ?? 0);

        if ($albumId > 0 && $songId > 0) {
            $trackNumbers = $this->geniusClient->albumTrackNumbers(
                $albumId,
                is_string(data_get($songPayload, 'album.url')) ? (string) data_get($songPayload, 'album.url') : null,
            );

            if (isset($trackNumbers[$songId]) && (int) $trackNumbers[$songId] > 0) {
                return (int) $trackNumbers[$songId];
            }
        }

        $directTrackNumber = (int) (
            $songPayload['song_number']
                ?? $songPayload['track_number']
                ?? $songPayload['number']
                ?? data_get($songPayload, 'album_appearance.track_number')
                ?? 0
        );

        if ($directTrackNumber > 0 && $directTrackNumber <= 80) {
            return $directTrackNumber;
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

        foreach ($parsedTrack->artistNames as $artistName) {
            foreach (GeniusNameMatcher::splitArtistCredits($artistName, $page->artistName) as $creditedArtistName) {
                $normalizedName = GeniusNameMatcher::normalizeLoose($creditedArtistName);

                if ($normalizedName === '' || $normalizedName === GeniusNameMatcher::normalizeLoose($page->artistName)) {
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

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbumFallback(Artist $primaryArtist, string $albumTitle): array
    {
        $normalizedTitle = Str::lower(GeniusNameMatcher::storageValue($albumTitle));

        $album = Album::query()
            ->where('artist_id', $primaryArtist->id)
            ->whereRaw('LOWER(title) = ?', [$normalizedTitle])
            ->first();

        $created = false;

        if (! $album) {
            $album = Album::create(GeniusNameMatcher::sanitizeForDatabase([
                'artist_id' => $primaryArtist->id,
                'title' => GeniusNameMatcher::storageValue($albumTitle),
                'slug' => $this->uniqueAlbumSlug(Str::slug(GeniusNameMatcher::storageValue($albumTitle)) ?: 'album'),
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
        $normalized = GeniusNameMatcher::normalizeLoose((string) ($albumTitle ?? ''));

        return $normalized !== '' && ! in_array($normalized, [
            'novinki',
            'novinka',
            'new',
            'news',
            'popularnoe',
            'populiarnoe',
            'top',
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

            $value = GeniusNameMatcher::storageValue($value);

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
            && array_intersect($geniusVersionFlags, ['live', 'remix', 'remixes', 'instrumental', 'karaoke', 'acoustic', 'edit', 'deluxe', 'ver2', 'remaster']) !== []) {
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

        $muzofondAlbumTitle = GeniusNameMatcher::storageValue((string) $muzofondAlbumTitle);
        $geniusAlbumTitle = GeniusNameMatcher::storageValue((string) ($geniusAlbumTitle ?? ''));

        if ($muzofondAlbumTitle === '') {
            return 0.0;
        }

        if ($geniusAlbumTitle === '') {
            return -0.08;
        }

        $baseScore = GeniusNameMatcher::score(
            $this->normalizeAlbumComparisonTitle($muzofondAlbumTitle),
            $this->normalizeAlbumComparisonTitle($geniusAlbumTitle),
        );

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
        $value = GeniusNameMatcher::storageValue($value);
        $value = preg_replace('/\b(?:live|remix(?:es)?|mix(?:es)?|instrumental|karaoke|demo|acoustic|radio\s+edit|edit|deluxe|expanded|bonus|ep|single|version|edition|remaster(?:ed)?)\b/iu', ' ', $value) ?? $value;
        $value = str_replace(['(', ')', '[', ']', '{', '}'], ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return GeniusNameMatcher::normalizeLoose($value);
    }

    private function versionMatchAdjustment(string $muzofondTitle, string $geniusTitle): float
    {
        $muzofondFlags = $this->versionFlags($muzofondTitle);
        $geniusFlags = $this->versionFlags($geniusTitle);

        if ($muzofondFlags === [] && $geniusFlags === []) {
            return 0.0;
        }

        if ($muzofondFlags === [] && $geniusFlags !== []) {
            return array_intersect($geniusFlags, ['instrumental', 'karaoke', 'live', 'remix', 'remixes', 'ep', 'edit']) !== []
                ? -0.22
                : -0.08;
        }

        if ($muzofondFlags !== [] && $geniusFlags === []) {
            return -0.14;
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

    private function debugMatchingEnabled(): bool
    {
        return (bool) config('services.genius.debug_matching', false);
    }
}
