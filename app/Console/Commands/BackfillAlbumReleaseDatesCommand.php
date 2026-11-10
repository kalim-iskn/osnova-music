<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Services\Genius\GeniusClient;
use Illuminate\Console\Command;
use Throwable;

class BackfillAlbumReleaseDatesCommand extends Command
{
    protected $signature = 'albums:backfill-genius-dates {--artist= : Artist slug} {--dry-run : Show changes without saving}';

    protected $description = 'Fill missing album release dates and date components from Genius artist album summaries';

    public function handle(GeniusClient $genius): int
    {
        $query = Album::query()
            ->with('artist')
            ->whereNotNull('genius_id')
            ->whereNull('release_date')
            ->whereNull('release_date_components');

        if ($slug = trim((string) $this->option('artist'))) {
            $query->whereHas('artist', fn ($artist) => $artist->where('slug', $slug));
        }

        $updated = 0;

        foreach ($query->get()->groupBy('artist_id') as $albums) {
            $artist = $albums->first()?->artist;
            $artistGeniusId = (int) ($artist?->genius_id ?? 0);

            if ($artistGeniusId <= 0) {
                continue;
            }

            try {
                $summaries = collect($genius->allArtistAlbums($artistGeniusId))
                    ->filter(fn ($album) => is_array($album) && (int) ($album['id'] ?? 0) > 0)
                    ->keyBy(fn (array $album) => (int) $album['id']);
            } catch (Throwable $exception) {
                $this->warn("{$artist->name}: Genius недоступен ({$exception->getMessage()})");

                continue;
            }

            foreach ($albums as $album) {
                $summary = $summaries->get((int) $album->genius_id);

                if (! is_array($summary)) {
                    continue;
                }

                $components = is_array($summary['release_date_components'] ?? null)
                    ? $summary['release_date_components']
                    : null;
                $date = $this->completeDate($summary, $components);

                if ($date === null && $components === null) {
                    continue;
                }

                $this->line(sprintf('%s / %s: %s', $artist->name, $album->title, $date ?? 'частичная дата'));

                if (! $this->option('dry-run')) {
                    $album->forceFill([
                        'release_date' => $date,
                        'release_date_components' => $components,
                    ])->save();
                }

                $updated++;
            }
        }

        $this->info("Обработано альбомов: {$updated}");

        return self::SUCCESS;
    }

    private function completeDate(array $summary, ?array $components): ?string
    {
        $value = (string) ($summary['release_date'] ?? '');

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches) === 1
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        $year = (int) ($components['year'] ?? 0);
        $month = (int) ($components['month'] ?? 0);
        $day = (int) ($components['day'] ?? 0);

        return $year > 0 && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }
}
