<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Track;
use App\Services\Genius\GeniusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use App\Support\ArtworkUrl;
use Throwable;

class RepairTrackAlbumsFromGeniusCommand extends Command
{
    protected $signature = 'tracks:repair-genius-albums
        {--track-id=* : Repair only specific local track ids}
        {--limit=0 : Limit how many tracks to inspect}
        {--dry-run : Show intended changes without writing them}';

    protected $description = 'Realign track primary/secondary album memberships to explicit Genius song album payloads';

    public function handle(GeniusClient $geniusClient): int
    {
        $trackIds = collect((array) $this->option('track-id'))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->values()
            ->all();
        $limit = max((int) $this->option('limit'), 0);
        $dryRun = (bool) $this->option('dry-run');

        $query = Track::query()
            ->with(['album', 'albums', 'artist', 'artists'])
            ->whereNotNull('genius_id')
            ->where('genius_id', '>', 0)
            ->orderBy('id');

        if ($trackIds !== []) {
            $query->whereIn('id', $trackIds);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $tracks = $query->get();

        if ($tracks->isEmpty()) {
            $this->info('No tracks with Genius ids were found for repair.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $touchedArtistIds = [];

        foreach ($tracks as $track) {
            $processed++;

            try {
                $songPayload = $geniusClient->song((int) $track->genius_id);
            } catch (Throwable $exception) {
                $skipped++;
                $this->warn(sprintf('Track %d skipped: failed to load Genius song payload (%s).', $track->id, $exception->getMessage()));

                continue;
            }

            if (! is_array($songPayload)) {
                $skipped++;
                $this->warn(sprintf('Track %d skipped: Genius song payload is unavailable.', $track->id));

                continue;
            }

            $explicitAlbumPayloads = $this->explicitAlbumPayloads($songPayload);

            if ($explicitAlbumPayloads->isEmpty()) {
                $skipped++;
                $this->line(sprintf('Track %d skipped: Genius song has no explicit album payload.', $track->id));

                continue;
            }

            $resolvedAlbums = $explicitAlbumPayloads
                ->mapWithKeys(fn (array $albumPayload) => [
                    (int) $albumPayload['id'] => $this->resolveLocalAlbum($track, $albumPayload, $dryRun),
                ])
                ->filter(fn ($album) => $album instanceof Album);

            if ($resolvedAlbums->isEmpty()) {
                $skipped++;
                $this->warn(sprintf('Track %d skipped: could not resolve any local albums from explicit Genius payloads.', $track->id));

                continue;
            }

            $primaryAlbum = $this->resolvePrimaryAlbum($songPayload, $resolvedAlbums);

            if (! $primaryAlbum instanceof Album) {
                $skipped++;
                $this->warn(sprintf('Track %d skipped: could not resolve primary album.', $track->id));

                continue;
            }

            $targetAlbumIds = $resolvedAlbums
                ->map(fn (Album $album) => (int) $album->id)
                ->unique()
                ->values()
                ->all();

            $currentAlbumIds = $track->albums
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $currentPrimaryAlbumId = (int) ($track->album_id ?? 0);

            $currentPrimaryPivotAlbumId = (int) ($track->albums->firstWhere('pivot.is_primary', true)?->id ?? 0);
            $needsRepair = $currentPrimaryAlbumId !== (int) $primaryAlbum->id
                || $currentPrimaryPivotAlbumId !== (int) $primaryAlbum->id
                || $currentAlbumIds !== $targetAlbumIds;

            if (! $needsRepair) {
                continue;
            }

            $message = sprintf(
                'Track %d "%s": primary %s -> %s; memberships [%s] -> [%s]',
                $track->id,
                $track->title,
                $currentPrimaryAlbumId > 0 ? (string) $currentPrimaryAlbumId : 'null',
                $primaryAlbum->id,
                implode(', ', $currentAlbumIds),
                implode(', ', $targetAlbumIds),
            );

            if ($dryRun) {
                $this->line('[dry-run] ' . $message);
                $updated++;

                continue;
            }

            $pivotPayload = [];

            foreach ($targetAlbumIds as $albumId) {
                $existingNumber = $track->albums->firstWhere('id', $albumId)?->pivot?->track_number;
                $pivotPayload[$albumId] = [
                    'is_primary' => $albumId === (int) $primaryAlbum->id,
                    'track_number' => $existingNumber
                        ?? ($albumId === (int) ($track->album_id ?? 0) ? $track->track_number : null),
                ];
            }

            $track->albums()->sync($pivotPayload);
            $track->forceFill([
                'album_id' => $primaryAlbum->id,
            ])->save();

            if ((int) ($primaryAlbum->artist_id ?? 0) > 0) {
                $touchedArtistIds[(int) $primaryAlbum->artist_id] = true;
            }

            $updated++;
            $this->info($message);
        }

        if (! $dryRun) {
            foreach (array_keys($touchedArtistIds) as $artistId) {
                Album::query()
                    ->where('artist_id', $artistId)
                    ->doesntHave('tracks')
                    ->delete();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Repair finished. Processed: %d, updated: %d, skipped: %d%s',
            $processed,
            $updated,
            $skipped,
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function explicitAlbumPayloads(array $songPayload): Collection
    {
        return collect(array_merge(
            [is_array($songPayload['album'] ?? null) ? $songPayload['album'] : null],
            array_values(array_filter((array) ($songPayload['albums'] ?? []), fn ($albumPayload) => is_array($albumPayload)))
        ))
            ->filter(fn ($albumPayload) => is_array($albumPayload) && (int) ($albumPayload['id'] ?? 0) > 0)
            ->unique(fn (array $albumPayload) => (int) ($albumPayload['id'] ?? 0))
            ->values();
    }

    /**
     * @param  Collection<int, Album>  $resolvedAlbums
     */
    private function resolvePrimaryAlbum(array $songPayload, Collection $resolvedAlbums): ?Album
    {
        $primaryAlbumGeniusId = (int) data_get($songPayload, 'album.id', 0);

        if ($primaryAlbumGeniusId > 0 && $resolvedAlbums->has($primaryAlbumGeniusId)) {
            return $resolvedAlbums->get($primaryAlbumGeniusId);
        }

        /** @var Album|null $firstAlbum */
        $firstAlbum = $resolvedAlbums->first();

        return $firstAlbum;
    }

    private function resolveLocalAlbum(Track $track, array $albumPayload, bool $dryRun): ?Album
    {
        $albumId = (int) ($albumPayload['id'] ?? 0);

        if ($albumId <= 0) {
            return null;
        }

        $existingAlbum = Album::query()
            ->where('genius_id', $albumId)
            ->first();

        if ($existingAlbum instanceof Album) {
            return $existingAlbum;
        }

        $title = trim((string) ($albumPayload['name'] ?? $albumPayload['title'] ?? ''));
        $artistId = (int) ($track->artist_id ?? 0);

        if ($title === '' || $artistId <= 0) {
            return null;
        }

        if ($dryRun) {
            return new Album([
                'id' => -$albumId,
                'artist_id' => $artistId,
                'title' => $title,
                'slug' => Str::slug($title) ?: 'album-' . $albumId,
                'release_date' => data_get($albumPayload, 'release_date'),
                'genius_id' => $albumId,
            ]);
        }

        $album = Album::query()->create([
            'artist_id' => $artistId,
            'title' => $title,
            'slug' => $this->uniqueAlbumSlug($artistId, Str::slug($title) ?: 'album-' . $albumId),
            'cover_image_url' => ArtworkUrl::firstUsable(
                data_get($albumPayload, 'cover_art_thumbnail_url'),
                data_get($albumPayload, 'cover_art_url'),
                data_get($albumPayload, 'image_url'),
            ),
            'release_date' => data_get($albumPayload, 'release_date'),
            'genius_id' => $albumId,
        ]);

        if (method_exists($album, 'artists') && $track->relationLoaded('artists') && $track->artists->isNotEmpty()) {
            $album->artists()->syncWithoutDetaching($track->artists->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return $album;
    }

    private function uniqueAlbumSlug(int $artistId, string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Album::query()->where('artist_id', $artistId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
