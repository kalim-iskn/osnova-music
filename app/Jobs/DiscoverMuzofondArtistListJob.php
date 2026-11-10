<?php

namespace App\Jobs;

use App\Models\ParsingRun;
use App\Models\ParsingTask;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DiscoverMuzofondArtistListJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $runId,
        public string $listingUrl,
        public int $artistLimit,
        public int $pageLimit,
    ) {
    }

    public function handle(MuzofondTrackParser $parser): void
    {
        $run = ParsingRun::query()->findOrFail($this->runId);
        $run->forceFill([
            'status' => 'running',
            'stage' => 'discovering_artists',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ])->save();

        try {
            $artistUrls = $parser->artistUrls($this->listingUrl, $this->artistLimit);

            if ($artistUrls === []) {
                throw new RuntimeException('На странице списка не найдено артистов.');
            }

            $tasks = DB::transaction(function () use ($run, $artistUrls): array {
                return collect($artistUrls)
                    ->map(fn (string $artistUrl) => $run->tasks()->create([
                        'artist_url' => $artistUrl,
                        'status' => 'queued',
                        'stage' => 'queued',
                        'page_limit' => $this->pageLimit,
                    ]))
                    ->all();
            });

            $run->forceFill([
                'total_tasks' => count($tasks),
                'status' => 'queued',
                'stage' => 'queueing_artists',
            ])->save();

            foreach ($tasks as $task) {
                ParseMuzofondArtistJob::dispatch(
                    $task->artist_url,
                    $this->pageLimit,
                    false,
                    false,
                    $task->id,
                )->onConnection('database')->onQueue('tracks-parsing');
            }

            $run->refreshFromTasks();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'queued',
                'stage' => $this->attempts() >= $this->tries ? 'failed' : 'retry_wait',
                'error' => mb_substr($exception->getMessage(), 0, 6000),
                'finished_at' => $this->attempts() >= $this->tries ? now() : null,
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ParsingRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'stage' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Не удалось прочитать список артистов.', 0, 6000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
