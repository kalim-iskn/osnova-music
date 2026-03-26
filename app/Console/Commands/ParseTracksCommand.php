<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ParseTracksCommand extends Command
{
    protected $signature = 'tracks:parse
        {parser : Parser key, for now only "muzofond"}
        {url : Artist page URL or artists listing URL}
        {--artist-limit=0 : Limit how many artists should be parsed from listing pages}
        {--page-limit=0 : Limit pagination depth for parser pages, 0 = no limit}
        {--dry-run : Parse only, do not save anything}';

    protected $description = 'Parse tracks from a remote page and save artists, albums and tracks into the local catalog';

    /**
     * @var string[]|null
     */
    private ?array $trackColumns = null;

    public function handle(MuzofondTrackParser $muzofondParser): int
    {
        $parserKey = Str::lower(trim((string) $this->argument('parser')));
        $url = trim((string) $this->argument('url'));
        $artistLimit = max((int) $this->option('artist-limit'), 0);
        $pageLimit = max((int) $this->option('page-limit'), 0);
        $dryRun = (bool) $this->option('dry-run');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Передан некорректный URL.');

            return self::FAILURE;
        }

        $pages = match ($parserKey) {
            'muzofond' => $muzofondParser->parse($url, $artistLimit, $pageLimit),
            default => null,
        };

        if ($pages === null) {
            $this->error(sprintf('Парсер "%s" не поддерживается.', $parserKey));

            return self::FAILURE;
        }

        if ($pages === []) {
            $this->warn('Парсер не нашёл артистов или треков.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено страниц артистов: %d', count($pages)));

        $savedArtists = 0;
        $savedAlbums = 0;
        $savedTracks = 0;

        foreach ($pages as $page) {
            $this->newLine();
            $this->line(sprintf('<info>%s</info> [%s]', $page->artistName, $page->artistSlug));
            $this->line(sprintf('Найдено треков: %d', count($page->tracks)));

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($page, &$savedArtists, &$savedAlbums, &$savedTracks): void {
                    [$primaryArtist, $artistCreated] = $this->upsertPrimaryArtist($page);

                    if ($artistCreated) {
                        $savedArtists++;
                    }

                    foreach ($page->tracks as $parsedTrack) {
                        $creditedArtists = $this->resolveCreditedArtists($page, $parsedTrack, $primaryArtist);

                        $albumId = null;

                        if ($parsedTrack->albumTitle !== null && trim($parsedTrack->albumTitle) !== '') {
                            [$album, $albumCreated] = $this->upsertAlbum($primaryArtist, $parsedTrack->albumTitle);

                            $albumId = $album->id;

                            if ($albumCreated) {
                                $savedAlbums++;
                            }
                        }

                        [$track, $trackCreated] = $this->upsertTrack(
                            $primaryArtist,
                            $albumId,
                            $parsedTrack
                        );

                        if ($trackCreated) {
                            $savedTracks++;
                        }

                        if (method_exists($track, 'artists')) {
                            $track->artists()->syncWithoutDetaching(
                                collect($creditedArtists)->pluck('id')->unique()->values()->all()
                            );
                        }
                    }
                });
            } catch (Throwable $exception) {
                $this->error(sprintf(
                    'Ошибка при сохранении артиста "%s": %s',
                    $page->artistName,
                    $exception->getMessage(),
                ));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run завершён, данные не сохранялись.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово. Новых артистов: %d, альбомов: %d, треков: %d',
            $savedArtists,
            $savedAlbums,
            $savedTracks,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertPrimaryArtist(ParsedArtistPage $page): array
    {
        $artist = Artist::query()->where('slug', $page->artistSlug)->first();
        $created = false;

        if (! $artist) {
            $artist = Artist::create([
                'name' => $page->artistName,
                'slug' => $page->artistSlug,
                'image_url' => $page->imageUrl,
            ]);

            $created = true;
        } else {
            $artist->forceFill([
                'name' => $artist->name ?: $page->artistName,
                'image_url' => $artist->image_url ?: $page->imageUrl,
            ])->save();
        }

        return [$artist, $created];
    }

    /**
     * @return Artist[]
     */
    private function resolveCreditedArtists(ParsedArtistPage $page, ParsedTrack $parsedTrack, Artist $primaryArtist): array
    {
        $artists = [$primaryArtist];

        foreach ($parsedTrack->artistNames as $artistName) {
            $normalizedName = $this->normalizeName($artistName);

            if ($normalizedName === '') {
                continue;
            }

            if ($normalizedName === $this->normalizeName($page->artistName)) {
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
        $name = trim($name);

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($artist) {
            return $artist;
        }

        $baseSlug = Str::slug($name);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'artist';
        $slug = $this->uniqueArtistSlug($baseSlug);

        return Artist::create([
            'name' => $name,
            'slug' => $slug,
            'image_url' => null,
        ]);
    }

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbum(Artist $primaryArtist, string $albumTitle): array
    {
        $normalizedTitle = $this->normalizeName($albumTitle);

        $album = Album::query()
            ->whereRaw('LOWER(title) = ?', [$normalizedTitle])
            ->first();

        $created = false;

        if (! $album) {
            $baseSlug = Str::slug($primaryArtist->slug . '-' . $albumTitle);
            $baseSlug = $baseSlug !== '' ? $baseSlug : 'album';
            $slug = $this->uniqueAlbumSlug($baseSlug);

            $album = Album::create([
                'artist_id' => $primaryArtist->id,
                'title' => trim($albumTitle),
                'slug' => $slug,
                'cover_image_url' => null,
                'release_date' => null,
            ]);

            $created = true;
        }

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrack(Artist $primaryArtist, ?int $albumId, ParsedTrack $parsedTrack): array
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
                ->whereRaw('LOWER(title) = ?', [$this->normalizeName($parsedTrack->title)])
                ->first();
        }

        $created = false;

        if (! $track) {
            $track = new Track();
            $created = true;
        }

        $payload = [
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => trim($parsedTrack->title),
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
        ];

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        if ($this->trackHasColumn('release_year')) {
            $payload['release_year'] = $parsedTrack->releaseYear;
        }

        if ($this->trackHasColumn('genres')) {
            $payload['genres'] = $parsedTrack->genres !== []
                ? json_encode(array_values(array_unique($parsedTrack->genres)), JSON_UNESCAPED_UNICODE)
                : null;
        }

        $track->forceFill($payload)->save();

        return [$track, $created];
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

    private function normalizeName(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return Str::lower($value);
    }

    private function trackHasColumn(string $column): bool
    {
        if ($this->trackColumns === null) {
            $this->trackColumns = Schema::getColumnListing((new Track())->getTable());
        }

        return in_array($column, $this->trackColumns, true);
    }
}
