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

        if (! $geniusArtist) {
            foreach ($page->tracks as $parsedTrack) {
                $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                $albumId = null;

                if ($parsedTrack->albumTitle !== null && trim($parsedTrack->albumTitle) !== '') {
                    [$album, $albumCreated] = $this->upsertAlbumFallback($artist, $parsedTrack->albumTitle);

                    $albumId = $album->id;
                    $albumsCreated += $albumCreated ? 1 : 0;
                }

                [$track, $trackCreated] = $this->upsertTrackFallback($artist, $albumId, $parsedTrack);
                $tracksCreated += $trackCreated ? 1 : 0;
                $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());
            }

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
                $trackResult = DB::transaction(function () use ($page, $parsedTrack, $songSummaries, $artist, $geniusArtist): array {
                    $matchedSummary = $this->findBestSongMatch($page, $parsedTrack, $songSummaries, $geniusArtist);

                    if (! $matchedSummary) {
                        return $this->saveFallbackTrackResult($page, $parsedTrack, $artist);
                    }

                    $songDetail = $this->geniusClient->song((int) $matchedSummary['id']);

                    if (! $songDetail) {
                        return $this->saveFallbackTrackResult($page, $parsedTrack, $artist);
                    }

                    [, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $songDetail);

                    return ['matched' => true, 'created' => $trackCreated, 'albums_created' => 0];
                });

                $tracksCreated += $trackResult['created'] ? 1 : 0;
                $albumsCreated += (int) ($trackResult['albums_created'] ?? 0);

                if (! $trackResult['matched']) {
                    $unmatchedTracks++;

                    continue;
                }

                $matchedTracks++;
            } catch (Throwable $exception) {
                $unmatchedTracks++;

                Log::warning('Genius sync skipped one track because of an exception.', [
                    'artist' => $page->artistName,
                    'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

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
     * @return array<string, mixed>|null
     */
    private function findBestSongMatch(ParsedArtistPage $page, ParsedTrack $parsedTrack, array $songSummaries, array $geniusArtist): ?array
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);

        if ($parsedTitle === '') {
            return null;
        }

        $bestScore = 0.0;
        $bestSummary = null;

        foreach ($songSummaries as $songSummary) {
            if (! $this->primaryArtistMatchesPageArtist($songSummary, $page, $geniusArtist)) {
                continue;
            }

            $summaryTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

            if ($summaryTitle === '') {
                continue;
            }

            $score = GeniusNameMatcher::score($parsedTitle, $summaryTitle);

            if ($score < 0.84) {
                continue;
            }

            $artistBoost = $this->songContainsArtist($songSummary, $page->artistName) ? 0.03 : 0.0;
            $score += $artistBoost;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSummary = $songSummary;
            }
        }

        return $bestScore >= 0.84 ? $bestSummary : null;
    }

    private function primaryArtistMatchesPageArtist(array $songSummary, ParsedArtistPage $page, array $geniusArtist): bool
    {
        $primaryArtist = $this->primaryArtistPayload($songSummary);

        if ($primaryArtist === null) {
            return false;
        }

        $primaryArtistId = (int) ($primaryArtist['id'] ?? 0);
        $matchedArtistId = (int) ($geniusArtist['id'] ?? 0);

        if ($primaryArtistId > 0 && $matchedArtistId > 0 && $primaryArtistId === $matchedArtistId) {
            return true;
        }

        $candidateNames = array_filter([
            (string) ($primaryArtist['name'] ?? ''),
            (string) ($songSummary['artist_names'] ?? ''),
        ]);

        return GeniusNameMatcher::bestArtistScore($page->artistName, $candidateNames) >= 0.92;
    }

    private function primaryArtistPayload(array $songPayload): ?array
    {
        $primaryArtist = $songPayload['primary_artist'] ?? null;

        if (is_array($primaryArtist) && isset($primaryArtist['id'])) {
            return $primaryArtist;
        }

        foreach ((array) ($songPayload['primary_artists'] ?? []) as $artist) {
            if (is_array($artist) && isset($artist['id'])) {
                return $artist;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchArtist(ParsedArtistPage $page): ?array
    {
        $candidates = collect($this->geniusClient->searchArtist($page->artistName))
            ->take(5)
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

            $score = $nameScore;

            if ($overlap >= 2) {
                $score += 0.1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestArtist = $artist;
            }
        }

        return $bestScore >= 0.82 ? $bestArtist : null;
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

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertPrimaryArtistFromMuzofond(ParsedArtistPage $page): array
    {
        $storedName = GeniusNameMatcher::storageValue($page->artistName);
        $preferredSlugBase = $this->artistSlugBase($storedName);

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
            ->first();

        if (! $artist) {
            $artist = Artist::query()
                ->where('slug', $preferredSlugBase)
                ->first();
        }

        $created = false;

        if (! $artist) {
            $artist = Artist::create([
                'name' => $storedName,
                'slug' => $this->uniqueArtistSlug($preferredSlugBase),
                'image_url' => $page->imageUrl,
                'description_preview' => null,
                'genius_id' => null,
            ]);

            $created = true;
        } else {
            $preferredSlug = $this->uniqueArtistSlug($preferredSlugBase, $artist->id);

            $artist->forceFill([
                'name' => $storedName,
                'slug' => $preferredSlug,
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
        $artist = Artist::query()
            ->where('genius_id', (int) $payload['id'])
            ->first();

        $created = false;

        if (! $artist) {
            $artist = $seedArtist;

            if (! $artist) {
                $artist = Artist::query()
                    ->whereRaw('LOWER(name) = ?', [Str::lower(GeniusNameMatcher::storageValue((string) ($payload['name'] ?? '')))])
                    ->first();
            }
        }

        $slugBase = $this->artistSlugBase((string) ($payload['name'] ?? 'artist'));

        if (! $artist) {
            $created = true;
            $artist = new Artist();
            $artist->slug = $this->uniqueArtistSlug($slugBase);
        } else {
            $artist->slug = $this->uniqueArtistSlug($slugBase, $artist->id);
        }

        $artistPayload = GeniusNameMatcher::sanitizeForDatabase([
            'name' => GeniusNameMatcher::storageValue((string) ($payload['name'] ?? '')),
            'image_url' => $this->geniusClient->artistImageUrl($payload),
            'description_preview' => isset($payload['description_preview']) ? GeniusNameMatcher::forceUtf8((string) $payload['description_preview']) : null,
            'genius_id' => (int) $payload['id'],
        ]);

        $artist->forceFill($artistPayload)->save();

        return [$artist, $created];
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

        if (! $album) {
            $title = GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $payload['title'] ?? ''));
            $album = Album::query()
                ->where('artist_id', $fallbackArtist->id)
                ->whereRaw('LOWER(title) = ?', [Str::lower($title)])
                ->first();
        }

        if (! $album) {
            $created = true;
            $album = new Album();
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($fallbackArtist->slug . '-' . GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $payload['title'] ?? '')))
                    ?: 'album'
            );
        } elseif (! $album->slug) {
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($fallbackArtist->slug . '-' . GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $payload['title'] ?? '')))
                    ?: 'album'
            );
        }

        $albumPayload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $fallbackArtist->id,
            'title' => GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $payload['title'] ?? '')),
            'cover_image_url' => $payload['cover_art_thumbnail_url']
                ?? $payload['cover_art_url']
                    ?? $payload['image_url']
                    ?? $album->cover_image_url,
            'release_date' => $payload['release_date'] ?? null,
            'genius_id' => (int) $payload['id'],
        ]);

        $album->forceFill($albumPayload)->save();

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrackFromGenius(Artist $fallbackArtist, ParsedTrack $parsedTrack, array $songPayload): array
    {
        $primaryPayload = $this->primaryArtistPayload($songPayload);

        $performerPayloads = collect(array_merge(
            $primaryPayload ? [$primaryPayload] : [],
            (array) ($songPayload['primary_artists'] ?? []),
            (array) ($songPayload['featured_artists'] ?? []),
        ))
            ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
            ->unique(fn ($artist) => (int) $artist['id'])
            ->values();

        $performers = new EloquentCollection();

        foreach ($performerPayloads as $index => $artistPayload) {
            [$artist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);
            $performers->push($artist);
        }

        if ($performers->isEmpty()) {
            $performers->push($fallbackArtist);
        }

        $primaryArtist = $performers->first();

        $album = null;
        $albumId = null;

        if (is_array($songPayload['album'] ?? null) && isset($songPayload['album']['id'])) {
            [$album] = $this->upsertAlbumFromGenius($primaryArtist, $songPayload['album']);
            $albumId = $album->id;
        }

        $track = Track::query()
            ->where('genius_id', (int) $songPayload['id'])
            ->orWhere(function ($query) use ($parsedTrack): void {
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
                ->whereRaw('LOWER(title) = ?', [
                    Str::lower(GeniusNameMatcher::canonicalTrack($parsedTrack->title)),
                ])
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
            'title' => GeniusNameMatcher::storageValue($parsedTrack->title),
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
            'audio_hash' => $this->resolveAudioHash($parsedTrack->audioUrl),
            'release_year' => $this->resolveReleaseYear($songPayload, $parsedTrack),
            'genres' => $genres !== [] ? $genres : ($parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null),
            'language' => GeniusNameMatcher::resolveLanguageCode((array) ($songPayload['tags'] ?? []), $songPayload['language'] ?? null),
            'description_preview' => isset($songPayload['description_preview']) ? GeniusNameMatcher::forceUtf8((string) $songPayload['description_preview']) : null,
            'genius_url' => $songPayload['url'] ?? null,
            'cover_image_url' => $album ? null : ($songPayload['song_art_image_thumbnail_url'] ?? $songPayload['song_art_image_url'] ?? $songPayload['header_image_url'] ?? null),
            'genius_id' => (int) $songPayload['id'],
            'genius_pageviews' => $this->scaledPageviews($songPayload),
        ]);

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        $track->forceFill($payload)->save();
        $track->artists()->sync($performers->pluck('id')->unique()->values()->all());

        return [$track, $created];
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

        $baseSlug = $this->artistSlugBase($storedName);

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
                'slug' => $this->uniqueAlbumSlug(Str::slug($primaryArtist->slug . '-' . $albumTitle) ?: 'album'),
                'cover_image_url' => null,
                'release_date' => null,
                'genius_id' => null,
            ]));

            $created = true;
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
            'audio_hash' => $this->resolveAudioHash($parsedTrack->audioUrl),
            'release_year' => $parsedTrack->releaseYear,
            'genres' => $parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null,
            'genius_pageviews' => 0,
        ]);

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        $track->forceFill($payload)->save();

        return [$track, $created];
    }

    /**
     * @return array{matched:false, created:bool, albums_created:int}
     */
    private function saveFallbackTrackResult(ParsedArtistPage $page, ParsedTrack $parsedTrack, Artist $artist): array
    {
        $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
        $albumId = null;
        $albumsCreated = 0;

        if ($parsedTrack->albumTitle !== null && trim($parsedTrack->albumTitle) !== '') {
            [$album, $albumCreated] = $this->upsertAlbumFallback($artist, $parsedTrack->albumTitle);
            $albumId = $album->id;
            $albumsCreated += $albumCreated ? 1 : 0;
        }

        [$track, $trackCreated] = $this->upsertTrackFallback($artist, $albumId, $parsedTrack);
        $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());

        return [
            'matched' => false,
            'created' => $trackCreated,
            'albums_created' => $albumsCreated,
        ];
    }

    private function scaledPageviews(array $songPayload): int
    {
        return max(0, (int) floor(((int) data_get($songPayload, 'stats.pageviews', 0)) / 20));
    }

    private function resolveAudioHash(?string $audioUrl): ?string
    {
        $audioUrl = trim((string) $audioUrl);

        return $audioUrl === '' ? null : sha1($audioUrl);
    }

    private function artistSlugBase(string $name): string
    {
        return Str::slug(GeniusNameMatcher::storageValue($name)) ?: 'artist';
    }

    private function uniqueArtistSlug(string $baseSlug, ?int $ignoreArtistId = null): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Artist::query()
            ->when($ignoreArtistId !== null, fn ($query) => $query->whereKeyNot($ignoreArtistId))
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
