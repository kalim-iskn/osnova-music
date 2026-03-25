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

    protected $description = 'Download track audio files to the local filesystem and switch tracks to local URLs';

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

        $diskName = (string) config('filesystems.default', 'public');
        $disk = Storage::disk($diskName);
        $downloadedCount = 0;

        foreach ($tracks as $track) {
            $sourceUrl = trim((string) $track->audio_url);

            if ($sourceUrl === '') {
                $this->warn("Трек #{$track->id} пропущен: пустой audio_url.");
                continue;
            }

            $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
            $extension = $extension !== '' ? strtolower($extension) : 'mp3';

            $fileName = Str::slug($track->artist?->name.' '.$track->title);
            $fileName = $fileName !== '' ? $fileName : 'track-'.$track->id;

            $storagePath = sprintf(
                'tracks/%06d-%s.%s',
                $track->id,
                $fileName,
                $extension
            );

            $tempDirectory = storage_path('app/tmp');
            $tempPath = $tempDirectory.'/'.Str::uuid().'.'.$extension;

            File::ensureDirectoryExists($tempDirectory);

            try {
                $response = Http::timeout(90)
                    ->withOptions(['sink' => $tempPath])
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
                    'audio_url' => $disk->url($storagePath),
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
}
