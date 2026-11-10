<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Track;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairTrackGeniusMatchesCommand extends Command
{
    protected $signature = 'tracks:repair-genius-matches
        {--artist-id=* : Repair tracks for specific local artist ids}
        {--track-id=* : Repair only specific local track ids}
        {--unmatched-only : Repair only tracks without a Genius match}
        {--ignore-album-context : Re-match selected tracks without trusting current local album titles}
        {--dry-run : Show intended changes without writing them}';

    protected $description = 'Re-run current Genius matching for existing local tracks and update them in place';

    public function handle(GeniusCatalogSyncService $geniusCatalogSyncService): int
    {
        $artistIds = $this->normalizedIds((array) $this->option('artist-id'));
        $trackIds = $this->normalizedIds((array) $this->option('track-id'));
        $unmatchedOnly = (bool) $this->option('unmatched-only');
        $ignoreAlbumContext = (bool) $this->option('ignore-album-context');
        $dryRun = (bool) $this->option('dry-run');

        $artists = $this->artistsToProcess($artistIds, $trackIds, $unmatchedOnly);

        if ($artists->isEmpty()) {
            $this->info('No artists were found for Genius repair.');

            return self::SUCCESS;
        }

        $processedArtists = 0;
        $processedTracks = 0;
        $changedTracks = 0;
        $failedArtists = 0;

        foreach ($artists as $artist) {
            $tracks = $this->tracksForArtist($artist, $trackIds, $unmatchedOnly);

            if ($tracks->isEmpty()) {
                continue;
            }

            $processedArtists++;
            $processedTracks += $tracks->count();
            $beforeSnapshots = $tracks
                ->mapWithKeys(fn (Track $track) => [$track->id => $this->trackSnapshot($track)])
                ->all();

            DB::beginTransaction();

            try {
                $result = $geniusCatalogSyncService->syncArtistPage(
                    $this->localCatalogPageForArtist($artist, $tracks, $ignoreAlbumContext)
                );

                $afterTracks = Track::query()
                    ->with('album')
                    ->whereIn('id', $tracks->pluck('id')->all())
                    ->orderBy('id')
                    ->get();

                $changes = $this->changedTrackMessages($beforeSnapshots, $afterTracks);
                $changedTracks += count($changes);

                if ($changes === []) {
                    $this->line(sprintf(
                        '%sArtist %d "%s": no changes (%d local tracks, matched %d, unmatched %d).',
                        $dryRun ? '[dry-run] ' : '',
                        $artist->id,
                        $artist->name,
                        $tracks->count(),
                        (int) ($result['matched_tracks'] ?? 0),
                        (int) ($result['unmatched_tracks'] ?? 0),
                    ));
                } else {
                    foreach ($changes as $message) {
                        $this->line(($dryRun ? '[dry-run] ' : '') . $message);
                    }
                }

                if ($dryRun) {
                    DB::rollBack();
                } else {
                    DB::commit();
                }
            } catch (Throwable $exception) {
                DB::rollBack();
                $failedArtists++;

                $this->warn(sprintf(
                    'Artist %d "%s" failed: %s',
                    $artist->id,
                    $artist->name,
                    $exception->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Genius repair finished. Artists: %d, tracks: %d, changed: %d, failed artists: %d%s',
            $processedArtists,
            $processedTracks,
            $changedTracks,
            $failedArtists,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failedArtists > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string|int>  $values
     * @return int[]
     */
    private function normalizedIds(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $artistIds
     * @param  int[]  $trackIds
     * @return EloquentCollection<int, Artist>
     */
    private function artistsToProcess(array $artistIds, array $trackIds, bool $unmatchedOnly): EloquentCollection
    {
        if ($trackIds !== []) {
            $resolvedArtistIds = Track::query()
                ->whereIn('id', $trackIds)
                ->pluck('artist_id')
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values()
                ->all();

            return Artist::query()
                ->whereIn('id', $resolvedArtistIds)
                ->orderBy('id')
                ->get();
        }

        return Artist::query()
            ->when($artistIds !== [], fn ($query) => $query->whereIn('id', $artistIds))
            ->whereHas('tracks', function ($query) use ($unmatchedOnly): void {
                if (! $unmatchedOnly) {
                    return;
                }

                $query->where(function ($subQuery): void {
                    $subQuery
                        ->whereNull('genius_id')
                        ->orWhere('genius_id', '<=', 0);
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  int[]  $trackIds
     * @return EloquentCollection<int, Track>
     */
    private function tracksForArtist(Artist $artist, array $trackIds, bool $unmatchedOnly): EloquentCollection
    {
        return Track::query()
            ->with(['album', 'artists', 'artist'])
            ->where('artist_id', $artist->id)
            ->when($trackIds !== [], fn ($query) => $query->whereIn('id', $trackIds))
            ->when($unmatchedOnly, function ($query): void {
                $query->where(function ($subQuery): void {
                    $subQuery
                        ->whereNull('genius_id')
                        ->orWhere('genius_id', '<=', 0);
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Track>  $tracks
     */
    private function localCatalogPageForArtist(Artist $artist, EloquentCollection $tracks, bool $ignoreAlbumContext): ParsedArtistPage
    {
        $parsedTracks = $tracks
            ->map(function (Track $track) use ($artist, $ignoreAlbumContext): ParsedTrack {
                $albumTitle = $ignoreAlbumContext
                    ? null
                    : ($track->muzofond_album_title ?: $track->album?->title);
                $trackNumber = $ignoreAlbumContext ? null : ($track->track_number !== null ? (int) $track->track_number : null);

                return new ParsedTrack(
                    title: (string) ($track->muzofond_title ?: $track->title),
                    durationSeconds: max(1, (int) ($track->duration_seconds ?? 0)),
                    audioUrl: $this->trackSourceUrl($track),
                    albumTitle: $albumTitle,
                    trackNumber: $trackNumber,
                    artistNames: $this->trackArtistNames($track, $artist),
                    releaseYear: $track->release_year !== null ? (int) $track->release_year : null,
                    genres: is_array($track->genres) ? array_values(array_filter($track->genres)) : [],
                    sourceType: $albumTitle !== null ? ParsedTrack::SOURCE_ALBUM_PAGE : ParsedTrack::SOURCE_ARTIST_PAGE,
                );
            })
            ->values()
            ->all();

        return new ParsedArtistPage(
            artistName: $artist->name,
            artistSlug: $artist->slug,
            imageUrl: $artist->image_url,
            tracks: $parsedTracks,
        );
    }

    /**
     * @return string[]
     */
    private function trackArtistNames(Track $track, Artist $fallbackArtist): array
    {
        $artistNames = $track->artists
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        if ($track->artist?->name && ! $artistNames->contains($track->artist->name)) {
            $artistNames->prepend($track->artist->name);
        }

        if (! $artistNames->contains($fallbackArtist->name)) {
            $artistNames->prepend($fallbackArtist->name);
        }

        return $artistNames
            ->unique()
            ->values()
            ->all();
    }

    private function trackSourceUrl(Track $track): string
    {
        foreach ([$track->original_link, $track->audio_url] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'track://' . $track->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function trackSnapshot(Track $track): array
    {
        return [
            'title' => (string) $track->title,
            'genius_id' => (int) ($track->genius_id ?? 0),
            'album_id' => (int) ($track->album_id ?? 0),
            'album_title' => (string) ($track->album?->title ?? ''),
            'genius_url' => (string) ($track->genius_url ?? ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $beforeSnapshots
     * @param  EloquentCollection<int, Track>  $afterTracks
     * @return string[]
     */
    private function changedTrackMessages(array $beforeSnapshots, EloquentCollection $afterTracks): array
    {
        $messages = [];

        foreach ($afterTracks as $track) {
            $before = $beforeSnapshots[$track->id] ?? null;

            if (! is_array($before)) {
                continue;
            }

            $after = $this->trackSnapshot($track);

            if ($before === $after) {
                continue;
            }

            $messages[] = sprintf(
                'Track %d "%s": genius %s -> %s; album %s -> %s; title "%s" -> "%s"',
                $track->id,
                $before['title'],
                $before['genius_id'] > 0 ? (string) $before['genius_id'] : 'null',
                $after['genius_id'] > 0 ? (string) $after['genius_id'] : 'null',
                $before['album_title'] !== '' ? $before['album_title'] : 'null',
                $after['album_title'] !== '' ? $after['album_title'] : 'null',
                $before['title'],
                $after['title'],
            );
        }

        return $messages;
    }
}
