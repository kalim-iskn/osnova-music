<?php

namespace App\Console\Commands;

use App\Models\Track;
use App\Services\AudioHasher;
use App\Services\Genius\GeniusNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CleanupDuplicateTracksCommand extends Command
{
    protected $signature = 'tracks:cleanup-duplicates
        {--limit=0 : Limit how many duplicate candidate groups should be processed, 0 = all}
        {--local-only : Hash only local downloaded files and skip remote sources}
        {--dry-run : Show duplicates without deleting them}';

    protected $description = 'Delete duplicate single tracks after verifying matching audio file hashes';

    public function handle(AudioHasher $audioHasher): int
    {
        $limit = max((int) $this->option('limit'), 0);
        $allowRemoteHashes = ! (bool) $this->option('local-only');
        $dryRun = (bool) $this->option('dry-run');

        $tracks = Track::query()
            ->with(['artist:id,name', 'artists:id,name'])
            ->whereNotNull('audio_url')
            ->orderBy('id')
            ->get();

        $candidateGroups = $tracks
            ->groupBy(fn (Track $track) => $this->duplicateCandidateKey($track))
            ->reject(fn (Collection $group, string $key) => $key === '' || $group->count() < 2)
            ->values();

        if ($limit > 0) {
            $candidateGroups = $candidateGroups->take($limit);
        }

        if ($candidateGroups->isEmpty()) {
            $this->info('Подходящих кандидатных групп дублей не найдено.');

            return self::SUCCESS;
        }

        $deletedTracks = 0;
        $duplicateBuckets = 0;
        $hashedTracks = 0;
        $skippedTracks = 0;

        foreach ($candidateGroups as $group) {
            $hashBuckets = [];

            foreach ($group as $track) {
                $hash = $this->resolveTrackFileHash($track, $audioHasher, $allowRemoteHashes);

                if ($hash === null) {
                    $skippedTracks++;

                    continue;
                }

                $hashedTracks++;
                $hashBuckets[$hash][] = $track;
            }

            foreach ($hashBuckets as $hash => $bucket) {
                $bucket = collect($bucket)->values();

                if ($bucket->count() < 2) {
                    continue;
                }

                $duplicateBuckets++;
                [$keptTracks, $tracksToDelete] = $this->partitionDuplicateTracks($bucket);

                if ($tracksToDelete->isEmpty()) {
                    continue;
                }

                foreach ($tracksToDelete as $track) {
                    if (! $dryRun) {
                        $track->delete();
                    }

                    $deletedTracks++;
                    $this->line(sprintf(
                        '%s %s | %s',
                        $dryRun ? '[dry-run delete]' : '[deleted]',
                        $track->title,
                        $this->trackSourceLabel($track),
                    ));
                }

                if ($this->output->isVerbose()) {
                    $this->line(sprintf(
                        '  hash=%s kept=%s',
                        $hash,
                        $keptTracks->map(fn (Track $track) => '#' . $track->id)->implode(', '),
                    ));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово. Кандидатных групп: %d, hash buckets: %d, захэшировано треков: %d, пропущено без file hash: %d, удалено: %d.',
            $candidateGroups->count(),
            $duplicateBuckets,
            $hashedTracks,
            $skippedTracks,
            $deletedTracks,
        ));

        return self::SUCCESS;
    }

    private function duplicateCandidateKey(Track $track): string
    {
        $titleKey = $this->strictComparisonKey((string) $track->title);
        $artistKeys = $this->strictArtistKeys($track);

        if ($titleKey === '' || $artistKeys === []) {
            return '';
        }

        return implode('|', $artistKeys) . '||' . $titleKey;
    }

    /**
     * @return string[]
     */
    private function strictArtistKeys(Track $track): array
    {
        $artistNames = collect($track->artists)
            ->pluck('name')
            ->filter()
            ->values();

        if ($track->artist?->name && ! $artistNames->contains($track->artist->name)) {
            $artistNames->prepend($track->artist->name);
        }

        return $artistNames
            ->map(fn (string $name) => $this->strictComparisonKey($name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function strictComparisonKey(string $value): string
    {
        $value = GeniusNameMatcher::storageValue($value);
        $value = Str::lower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return '';
        }

        $transliterated = Str::lower(Str::transliterate($value));
        $transliterated = preg_replace('/[^a-z0-9\s]+/u', ' ', $transliterated) ?? $transliterated;
        $transliterated = trim((string) preg_replace('/\s+/u', ' ', $transliterated));

        return $transliterated !== '' ? $transliterated : $value;
    }

    private function resolveTrackFileHash(Track $track, AudioHasher $audioHasher, bool $allowRemoteHashes): ?string
    {
        $storedHash = Str::lower(trim((string) ($track->audio_hash ?? '')));

        if (preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1) {
            return $storedHash;
        }

        $localPath = $this->localAudioPath($track);

        if ($localPath !== null) {
            return $audioHasher->hashLocalFile($localPath);
        }

        if (! $allowRemoteHashes) {
            return null;
        }

        $sourceUrl = $this->remoteAudioSourceUrl($track);

        if ($sourceUrl === null) {
            return null;
        }

        return $audioHasher->hashRemote($sourceUrl, $this->requestHeadersFor($sourceUrl));
    }

    private function localAudioPath(Track $track): ?string
    {
        $audioUrl = trim((string) $track->audio_url);

        if ($audioUrl === '') {
            return null;
        }

        if (str_starts_with($audioUrl, '/storage/')) {
            return Storage::disk('public')->path(ltrim(Str::after($audioUrl, '/storage/'), '/'));
        }

        if (str_starts_with($audioUrl, 'storage/')) {
            return Storage::disk('public')->path(ltrim(Str::after($audioUrl, 'storage/'), '/'));
        }

        return null;
    }

    private function remoteAudioSourceUrl(Track $track): ?string
    {
        foreach ([$track->original_link, $track->audio_url] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Track>  $tracks
     * @return array{0: Collection<int, Track>, 1: Collection<int, Track>}
     */
    private function partitionDuplicateTracks(Collection $tracks): array
    {
        $protectedTracks = $tracks
            ->filter(fn (Track $track) => (int) ($track->genius_id ?? 0) > 0 || $track->album_id !== null)
            ->values();

        $deletableTracks = $tracks
            ->reject(fn (Track $track) => (int) ($track->genius_id ?? 0) > 0 || $track->album_id !== null)
            ->sort(fn (Track $left, Track $right) => $this->compareDeletableTracks($left, $right))
            ->values();

        if ($protectedTracks->isNotEmpty()) {
            return [$protectedTracks, $deletableTracks];
        }

        if ($deletableTracks->count() <= 1) {
            return [$deletableTracks, collect()];
        }

        return [
            collect([$deletableTracks->first()])->filter()->values(),
            $deletableTracks->slice(1)->values(),
        ];
    }

    private function compareDeletableTracks(Track $left, Track $right): int
    {
        $leftDownloaded = (bool) ($left->is_downloaded ?? false);
        $rightDownloaded = (bool) ($right->is_downloaded ?? false);

        if ($leftDownloaded !== $rightDownloaded) {
            return $rightDownloaded <=> $leftDownloaded;
        }

        $leftPlays = (int) ($left->plays_count ?? 0);
        $rightPlays = (int) ($right->plays_count ?? 0);

        if ($leftPlays !== $rightPlays) {
            return $rightPlays <=> $leftPlays;
        }

        return $left->id <=> $right->id;
    }

    private function trackSourceLabel(Track $track): string
    {
        return trim((string) ($track->original_link ?: $track->audio_url));
    }

    private function requestHeadersFor(string $sourceUrl): array
    {
        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        $origin = $scheme && $host ? $scheme . '://' . $host : null;

        return array_filter([
            'Accept' => 'audio/mpeg,audio/*;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Origin' => $origin,
            'Referer' => $origin ? $origin . '/' : null,
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
        ]);
    }
}
