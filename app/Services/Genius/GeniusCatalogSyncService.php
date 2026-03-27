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

        $albumsCreated += $this->syncArtistAlbums($artist, (int) $geniusArtist['id']);

        $songSummaries = collect($this->geniusClient->allArtistSongs((int) $geniusArtist['id']))
            ->reject(function (array $song): bool {
                $title = GeniusNameMatcher::normalizeLoose((string) ($song['title'] ?? ''));

                return str_contains($title, 'romanized') || str_contains($title, 'translation');
            })
            ->values()
            ->all();

        foreach ($page->tracks as $parsedTrack) {
            try {
                $trackResult = DB::transaction(function () use ($page, $parsedTrack, $songSummaries, $artist, &$usedSongIds, &$albumsCreated): array {
                    $matchedSong = $this->findBestSongMatch($page, $parsedTrack, $songSummaries, $usedSongIds);

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
    private function findBestSongMatch(ParsedArtistPage $page, ParsedTrack $parsedTrack, array $songSummaries, array $usedSongIds = []): ?array
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? GeniusNameMatcher::storageValue((string) $parsedTrack->albumTitle)
            : null;

        if ($parsedTitle === '') {
            return null;
        }

        $candidates = [];

        foreach ($songSummaries as $songSummary) {
            $summaryTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

            if ($summaryTitle === '') {
                continue;
            }

            if (! $this->songPrimaryArtistMatchesPage($songSummary, $page->artistName)) {
                continue;
            }

            $songAlbumTitle = $this->extractSongAlbumTitle($songSummary);
            $titleScore = GeniusNameMatcher::score($parsedTitle, $summaryTitle);
            $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $score = $titleScore + $versionScore + $albumScore;

            if ($score < ($parsedAlbumTitle ? 0.66 : 0.76)) {
                continue;
            }

            $artistBoost = $this->songContainsArtist($songSummary, $page->artistName) ? 0.04 : 0.0;
            $duplicatePenalty = in_array((int) ($songSummary['id'] ?? 0), $usedSongIds, true) ? 0.06 : 0.0;
            $albumPresenceBoost = $parsedAlbumTitle && $songAlbumTitle ? 0.02 : 0.0;

            $candidates[] = [
                'score' => $score + $artistBoost + $albumPresenceBoost - $duplicatePenalty,
                'song' => $songSummary,
                'used' => in_array((int) ($songSummary['id'] ?? 0), $usedSongIds, true),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if ($left['used'] !== $right['used']) {
                return $left['used'] <=> $right['used'];
            }

            return $right['score'] <=> $left['score'];
        });

        foreach (array_slice($candidates, 0, 6) as $candidate) {
            $songSummary = $candidate['song'] ?? null;

            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            $songDetail = $this->geniusClient->song((int) $songSummary['id']);

            if (! is_array($songDetail)) {
                continue;
            }

            if (! $this->songPrimaryArtistMatchesPage($songDetail, $page->artistName)) {
                continue;
            }

            if (! $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            if (! $this->songAlbumMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            return [
                'summary' => $songSummary,
                'detail' => $songDetail,
            ];
        }

        return null;
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
            ->take(25)
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
            ->all();

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

        return GeniusNameMatcher::bestArtistScore($artistName, $primaryArtistNames) >= 0.82;
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

    private function syncArtistAlbums(Artist $artist, int $geniusArtistId): int
    {
        $created = 0;

        foreach ($this->geniusClient->allArtistAlbums($geniusArtistId) as $albumSummary) {
            $albumDetail = $this->geniusClient->album((int) $albumSummary['id']) ?? $albumSummary;
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
            [$album] = $this->upsertAlbumFromGenius($fallbackArtist, $albumPayload);
            $albumId = $album->id;
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
        $directTrackNumber = (int) (
            $songPayload['song_number']
                ?? $songPayload['track_number']
                ?? $songPayload['number']
                ?? data_get($songPayload, 'album_appearance.track_number')
                ?? 0
        );

        if ($directTrackNumber > 0) {
            return $directTrackNumber;
        }

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

    /**
     * @return Artist[]
     */
    private function resolveFallbackCreditedArtists(ParsedArtistPage $page, ParsedTrack $parsedTrack, Artist $primaryArtist): array
    {
        $artists = [$primaryArtist];

        foreach ($parsedTrack->artistNames as $artistName) {
            $normalizedName = GeniusNameMatcher::normalizeLoose($artistName);

            if ($normalizedName === '' || $normalizedName === GeniusNameMatcher::normalizeLoose($page->artistName)) {
                continue;
            }

            $artists[] = $this->upsertArtistByName($artistName);
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

        if (! $muzofondHasCompilationMarkers && $geniusHasCompilationMarkers) {
            return -0.24;
        }

        if ((str_contains($normalizedGenius, $normalizedMuzofond) || str_contains($normalizedMuzofond, $normalizedGenius))
            && $this->versionFlags($muzofondAlbumTitle) === []
            && $this->versionFlags($geniusAlbumTitle) === []) {
            return -0.18;
        }

        return 0.0;
    }

    private function hasCompilationMarkers(string $title): bool
    {
        $normalized = GeniusNameMatcher::normalizeLoose($title);

        return preg_match('/\b(?:best of|greatest hits|hits|anthology|collection|collected|essentials|complete|selected|singles|compilation|box set|chapters|chronicles)\b/iu', $normalized) === 1;
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
}
