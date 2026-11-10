<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\SourceAudioVersionAnalyzer;
use Illuminate\Console\Command;

class AuditAlbumMembershipsCommand extends Command
{
    protected $signature = 'tracks:audit-albums {--artist= : Artist slug}';

    protected $description = 'Report missing album positions, repeated unmarked titles, and tracks hidden from album pages';

    public function handle(SourceAudioVersionAnalyzer $versions): int
    {
        $query = Album::query()->with(['artist', 'tracks', 'primaryTracks'])->orderBy('id');

        if ($slug = trim((string) $this->option('artist'))) {
            $query->whereHas('artist', fn ($artist) => $artist->where('slug', $slug));
        }

        foreach ($query->get() as $album) {
            $numbers = $album->tracks
                ->map(fn ($track) => (int) ($track->pivot?->track_number ?? 0))
                ->filter(fn (int $number) => $number > 0)
                ->values()
                ->all();
            $missing = $numbers !== [] ? array_values(array_diff(range(1, max($numbers)), $numbers)) : [];
            $repeated = $album->tracks
                ->filter(fn ($track) => ! $versions->hasVersionMarker((string) ($track->muzofond_title ?: $track->title)))
                ->groupBy(fn ($track) => GeniusNameMatcher::canonicalTrack((string) ($track->muzofond_title ?: $track->title)))
                ->filter(fn ($tracks, $title) => $title !== '' && $tracks->count() > 1)
                ->keys()
                ->values()
                ->all();
            $visibleIds = $album->tracks->pluck('id')->all();
            $hiddenCount = $album->primaryTracks->whereNotIn('id', $visibleIds)->count();

            $this->line(sprintf(
                '%s / %s: показано %d, пропуски [%s], повторы [%s], primary без связи %d',
                $album->artist?->name ?? '?',
                $album->title,
                $album->tracks->count(),
                implode(', ', $missing),
                implode(', ', $repeated),
                $hiddenCount,
            ));
        }

        return self::SUCCESS;
    }
}
