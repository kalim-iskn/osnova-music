<?php

namespace App\Console\Commands;

use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DownloadTracksCommand extends Command
{
    protected $signature = 'tracks:download
        {--limit=50 : How many tracks should be downloaded in one run}';

    protected $description = 'Download track audio files to local storage, keep the original source link and switch tracks to relative local paths';

    public function handle(): int
    {
        $limit = max((int) $this->option('limit'), 1);

        $tracks = Track::query()
            ->with('artist')
            ->where('is_downloaded', false)
            ->whereNotNull('audio_url')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($tracks->isEmpty()) {
            $this->info('Все подходящие треки уже скачаны.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('public');
        $downloadedCount = 0;

        foreach ($tracks as $track) {
            $sourceUrl = trim((string) $track->original_link ?: (string) $track->audio_url);

            if ($sourceUrl === '' || ! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
                $this->warn("Трек #{$track->id} пропущен: ссылка на исходный файл некорректна.");
                continue;
            }

            $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
            $extension = $extension !== '' ? strtolower($extension) : 'mp3';

            $fileName = Str::slug(trim(($track->artist?->name ?? '').' '.$track->title));
            $fileName = $fileName !== '' ? $fileName : 'track-'.$track->id;

            $storagePath = sprintf('tracks/%06d-%s.%s', $track->id, $fileName, $extension);
            $tempDirectory = storage_path('app/tmp');
            $tempPath = $tempDirectory.'/'.Str::uuid().'.'.$extension;

            File::ensureDirectoryExists($tempDirectory);

            try {
                $response = Http::timeout(120)
                    ->withHeaders($this->requestHeadersFor($sourceUrl))
                    ->withOptions([
                        'sink' => $tempPath,
                        'allow_redirects' => true,
                        'http_errors' => false,
                        'verify' => false,
                    ])
                    ->get($sourceUrl);

                if (! $response->successful()) {
                    File::delete($tempPath);
                    $this->warn("Трек #{$track->id} не скачан: удалённый сервер вернул {$response->status()}.");
                    continue;
                }

                $stream = fopen($tempPath, 'r');

                if ($stream === false) {
                    File::delete($tempPath);
                    $this->warn("Трек #{$track->id} не скачан: не удалось открыть временный файл.");
                    continue;
                }

                try {
                    $disk->put($storagePath, $stream);
                } finally {
                    fclose($stream);
                    File::delete($tempPath);
                }

                $track->forceFill([
                    'original_link' => $track->original_link ?: $sourceUrl,
                    'audio_url' => '/storage/'.$storagePath,
                    'is_downloaded' => true,
                ])->save();

                $downloadedCount++;
                $this->info("Скачан трек #{$track->id}: {$track->title}");
            } catch (Throwable $exception) {
                File::delete($tempPath);
                $this->warn("Трек #{$track->id} не скачан: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Готово. Скачано {$downloadedCount} из {$tracks->count()} треков.");

        return self::SUCCESS;
    }

    private function requestHeadersFor(string $sourceUrl): array
    {
        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        $origin = $scheme && $host ? $scheme.'://'.$host : null;

        return array_filter([
            'Accept' => 'audio/mpeg,audio/*;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Origin' => $origin,
            'Referer' => $origin ? $origin.'/' : null,
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
        ]);
    }
}
