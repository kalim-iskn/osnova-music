<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\MuzofondTrackParser;
use App\Services\AudioHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
     * @var array<string, string|null>
     */
    private array $audioHashCache = [];

    public function handle(MuzofondTrackParser $muzofondParser, AudioHasher $audioHasher): int
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
            $this->warn('Парсер не нашёл ни одного артиста или трека.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено страниц артистов: %d', count($pages)));

        $savedArtists = 0;
        $savedTracks = 0;
        $savedAlbums = 0;

        foreach ($pages as $page) {
            $this->newLine();
            $this->line(sprintf('<info>%s</info> [%s]', $page->artistName, $page->artistSlug));
            $this->line(sprintf('Треков найдено: %d', count($page->tracks)));

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($page, $audioHasher, &$savedArtists, &$savedTracks, &$savedAlbums): void {
                    [$artist, $artistCreated] = $this->upsertPrimaryArtist($page);

                    if ($artistCreated) {
                        $savedArtists++;
                    }

                    foreach ($page->tracks as $parsedTrack) {
                        $audioHash = $this->resolveAudioHash($parsedTrack, $audioHasher);

                        $collaboratorIds = collect($parsedTrack->artistNames)
                            ->filter(fn ($name) => trim((string) $name) !== '')
                            ->map(fn (string $name) => $this->upsertArtistByName($name)->id)
                            ->unique()
                            ->values();

                        if (! $collaboratorIds->contains($artist->id)) {
                            $collaboratorIds->prepend($artist->id);
                        }

                        $albumId = null;

                        if ($parsedTrack->albumTitle !== null && trim($parsedTrack->albumTitle) !== '') {
                            [$album, $albumCreated] = $this->upsertAlbum($artist, $parsedTrack->albumTitle);

                            if ($albumCreated) {
                                $savedAlbums++;
                            }

                            $albumId = $album->id;
                        }

                        [$track, $trackCreated] = $this->upsertTrack(
                            $artist,
                            $albumId,
                            $parsedTrack,
                            $audioHash,
                        );

                        if ($trackCreated) {
                            $savedTracks++;
                        }

                        $track->artists()->syncWithoutDetaching($collaboratorIds->all());
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
                'name' => $page->artistName,
                'image_url' => $artist->image_url ?: $page->imageUrl,
            ])->save();
        }

        return [$artist, $created];
    }

    private function upsertArtistByName(string $name): Artist
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Имя артиста не может быть пустым.');
        }

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

    private function upsertAlbum(Artist $artist, string $title): array
    {
        $title = trim($title);

        $album = Album::query()
            ->whereRaw('LOWER(title) = ?', [Str::lower($title)])
            ->first();

        $created = false;

        if (! $album) {
            $baseSlug = Str::slug($artist->slug . '-' . $title);
            $baseSlug = $baseSlug !== '' ? $baseSlug : 'album';
            $slug = $this->uniqueAlbumSlug($baseSlug);

            $album = Album::create([
                'artist_id' => $artist->id,
                'title' => $title,
                'slug' => $slug,
                'cover_image_url' => null,
                'release_date' => null,
            ]);

            $created = true;
        }

        return [$album, $created];
    }

    private function upsertTrack(
        Artist $primaryArtist,
        ?int $albumId,
        ParsedTrack $parsedTrack,
        ?string $audioHash,
    ): array {
        $title = trim($parsedTrack->title);
        $audioUrl = trim($parsedTrack->audioUrl);

        $track = null;

        if ($audioHash !== null) {
            $track = Track::query()->where('audio_hash', $audioHash)->first();
        }

        if (! $track && $audioUrl !== '') {
            $track = Track::query()
                ->where(function ($query) use ($audioUrl) {
                    $query
                        ->where('audio_url', $audioUrl)
                        ->orWhere('original_link', $audioUrl);
                })
                ->first();
        }

        if (! $track) {
            $track = Track::query()
                ->where('artist_id', $primaryArtist->id)
                ->where('album_id', $albumId)
                ->whereRaw('LOWER(title) = ?', [Str::lower($title)])
                ->first();
        }

        $created = false;

        if (! $track) {
            $track = new Track();
            $created = true;
        }

        $track->forceFill([
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => $title,
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $audioUrl,
            'original_link' => $track->original_link ?: $audioUrl,
            'audio_hash' => $audioHash ?: $track->audio_hash,
            'release_year' => $parsedTrack->releaseYear,
            'genres' => $parsedTrack->genres !== [] ? array_values($parsedTrack->genres) : null,
            'cover_image_url' => $track->cover_image_url,
            'track_number' => $track->track_number,
            'is_downloaded' => (bool) $track->is_downloaded,
            'plays_count' => (int) $track->plays_count,
        ])->save();

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

    private function resolveAudioHash(ParsedTrack $track, AudioHasher $audioHasher): ?string
    {
        $audioUrl = trim($track->audioUrl);

        if ($audioUrl === '') {
            return null;
        }

        if (array_key_exists($audioUrl, $this->audioHashCache)) {
            return $this->audioHashCache[$audioUrl];
        }

        $headers = $this->requestHeadersFor($audioUrl);
        $this->audioHashCache[$audioUrl] = $audioHasher->hashRemote($audioUrl, $headers);

        return $this->audioHashCache[$audioUrl];
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
