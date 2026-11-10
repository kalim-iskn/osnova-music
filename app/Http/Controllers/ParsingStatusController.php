<?php

namespace App\Http\Controllers;

use App\Jobs\DiscoverMuzofondArtistListJob;
use App\Jobs\ParseMuzofondArtistJob;
use App\Models\ParsingRun;
use App\Models\ParsingTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Inertia\Inertia;
use Inertia\Response;

class ParsingStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tools/Parsing');
    }

    public function status(): JsonResponse
    {
        $runs = ParsingRun::query()->latest('id')->limit(25)->get();
        $runIds = $runs->pluck('id');
        $tasksByRun = ParsingTask::query()
            ->whereIn('parsing_run_id', $runIds)
            ->orderByRaw("CASE WHEN status = 'running' THEN 0 WHEN status = 'queued' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->groupBy('parsing_run_id');
        $taskIds = $tasksByRun->flatten(1)->pluck('id');
        $checkpointsByTask = $taskIds->isEmpty()
            ? collect()
            : DB::table('parsing_task_tracks')
                ->whereIn('parsing_task_id', $taskIds)
                ->selectRaw('parsing_task_id, COUNT(*) as checkpointed, SUM(CASE WHEN completed THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN completed AND matched THEN 1 ELSE 0 END) as matched')
                ->groupBy('parsing_task_id')
                ->get()
                ->keyBy('parsing_task_id');
        $cancelledTasksByRun = ParsingTask::query()
            ->whereIn('parsing_run_id', $runIds)
            ->where('status', 'cancelled')
            ->selectRaw('parsing_run_id, COUNT(*) as total')
            ->groupBy('parsing_run_id')
            ->pluck('total', 'parsing_run_id');

        $queueName = 'tracks-parsing';
        $queueJobs = DB::table('jobs')->where('queue', $queueName)->get(['created_at', 'reserved_at']);
        $pendingJobs = $queueJobs->whereNull('reserved_at')->count();
        $reservedJobs = $queueJobs->whereNotNull('reserved_at')->count();
        $oldestPending = $queueJobs->whereNull('reserved_at')->min('created_at');
        $trackedQueued = ParsingTask::query()->where('status', 'queued')->count();
        $trackedRunning = ParsingTask::query()->where('status', 'running')->count();

        return response()->json([
            'queue' => [
                'name' => $queueName,
                'pending' => $pendingJobs,
                'reserved' => $reservedJobs,
                'untracked_pending' => max(0, $pendingJobs - $trackedQueued),
                'untracked_reserved' => max(0, $reservedJobs - $trackedRunning),
                'oldest_wait_seconds' => $oldestPending ? max(0, now()->timestamp - (int) $oldestPending) : 0,
                'worker_state' => $reservedJobs > 0
                    ? 'processing'
                    : ($pendingJobs > 0 ? 'waiting_for_worker' : 'idle'),
            ],
            'runs' => $runs->map(fn (ParsingRun $run): array => [
                'id' => $run->id,
                'kind' => $run->kind,
                'source_url' => $run->source_url,
                'status' => $run->status,
                'stage' => $run->stage,
                'options' => $run->options ?? [],
                'total_tasks' => (int) $run->total_tasks,
                'completed_tasks' => (int) $run->completed_tasks,
                'failed_tasks' => (int) $run->failed_tasks,
                'cancelled_tasks' => (int) ($cancelledTasksByRun->get($run->id) ?? 0),
                'error' => $run->error,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'created_at' => $run->created_at?->toIso8601String(),
                'duration_seconds' => $run->started_at
                    ? max(0, ($run->finished_at ?? now())->getTimestamp() - $run->started_at->getTimestamp())
                    : null,
                'tasks' => ($tasksByRun->get($run->id) ?? collect())->map(function (ParsingTask $task) use ($checkpointsByTask): array {
                    $checkpoints = $checkpointsByTask->get($task->id);
                    $hasCheckpoints = $checkpoints !== null;
                    $processed = $hasCheckpoints ? (int) $checkpoints->completed : (int) $task->tracks_processed;
                    $matched = $hasCheckpoints ? (int) $checkpoints->matched : (int) $task->matched_tracks;

                    return [
                    'id' => $task->id,
                    'artist_url' => $task->artist_url,
                    'artist_slug' => $task->artist_slug,
                    'artist_name' => $task->artist_name,
                    'status' => $task->status,
                    'stage' => $task->stage,
                    'attempts' => (int) $task->attempts,
                    'page_limit' => (int) $task->page_limit,
                    'tracks_total' => (int) $task->tracks_total,
                    'tracks_processed' => $processed,
                    'matched_tracks' => $matched,
                    'unmatched_tracks' => $hasCheckpoints ? max(0, $processed - $matched) : (int) $task->unmatched_tracks,
                    'current_track' => $task->current_track,
                    'muzofond_duration_ms' => $task->muzofond_duration_ms === null ? null : (int) $task->muzofond_duration_ms,
                    'genius_metrics' => $task->genius_metrics ?? [],
                    'error' => $task->error,
                    'can_cancel' => $this->canCancelTask($task),
                    'started_at' => $task->started_at?->toIso8601String(),
                    'finished_at' => $task->finished_at?->toIso8601String(),
                    'duration_seconds' => $task->started_at
                        ? max(0, ($task->finished_at ?? now())->getTimestamp() - $task->started_at->getTimestamp())
                        : null,
                    ];
                })->values()->all(),
            ])->values()->all(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function cancelTask(ParsingTask $task): JsonResponse
    {
        return DB::transaction(function () use ($task): JsonResponse {
            $task = ParsingTask::query()->lockForUpdate()->findOrFail($task->id);

            if (! $this->canCancelTask($task)) {
                throw new ConflictHttpException('Убрать можно только задание, которое ещё не запускалось или ожидает повтора.');
            }

            $queueJobs = DB::table('jobs')
                ->where('queue', 'tracks-parsing')
                ->where('payload', 'like', '%ParseMuzofondArtistJob%')
                ->lockForUpdate()
                ->get(['id', 'payload', 'reserved_at']);
            $matchingJobIds = [];
            $hasReservedMatch = false;
            $taskIdPattern = '/s:6:"taskId";i:' . preg_quote((string) $task->id, '/') . ';/';

            foreach ($queueJobs as $queueJob) {
                $payload = json_decode((string) $queueJob->payload, true);
                $command = data_get($payload, 'data.command');

                if (($payload['displayName'] ?? null) !== ParseMuzofondArtistJob::class
                    || ! is_string($command)
                    || preg_match($taskIdPattern, $command) !== 1) {
                    continue;
                }

                if ($queueJob->reserved_at !== null) {
                    $hasReservedMatch = true;
                    continue;
                }

                $matchingJobIds[] = (int) $queueJob->id;
            }

            if ($hasReservedMatch || $matchingJobIds === []) {
                throw new ConflictHttpException('Задание уже забрал обработчик или оно больше не находится в очереди. Обновите статус.');
            }

            DB::table('jobs')->whereIn('id', $matchingJobIds)->delete();
            $task->forceFill([
                'status' => 'cancelled',
                'stage' => 'cancelled',
                'current_track' => null,
                'finished_at' => now(),
            ])->save();
            $task->run?->refreshFromTasks();

            return response()->json(['message' => 'Задание удалено из очереди.']);
        });
    }

    private function canCancelTask(ParsingTask $task): bool
    {
        return $task->status === 'queued'
            && ($task->started_at === null || $task->stage === 'retry_wait');
    }

    public function enqueueArtist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'artist' => ['required', 'string', 'max:2048'],
            'page_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $artistUrl = $this->normalizeArtistUrl((string) $data['artist']);

        if ($artistUrl === null) {
            throw ValidationException::withMessages([
                'artist' => 'Вставьте slug или ссылку артиста вида muzofond.fm/collections/artists/slug.',
            ]);
        }

        $pageLimit = (int) ($data['page_limit'] ?? 0);
        $run = ParsingRun::query()->create([
            'kind' => 'artist',
            'source_url' => $artistUrl,
            'status' => 'queued',
            'stage' => 'queued',
            'options' => ['page_limit' => $pageLimit],
            'total_tasks' => 1,
        ]);
        $task = $run->tasks()->create([
            'artist_url' => $artistUrl,
            'artist_slug' => $this->slugFromArtistUrl($artistUrl),
            'status' => 'queued',
            'stage' => 'queued',
            'page_limit' => $pageLimit,
        ]);

        ParseMuzofondArtistJob::dispatch($artistUrl, $pageLimit, false, false, $task->id)
            ->onConnection('database')
            ->onQueue('tracks-parsing');

        return response()->json(['message' => 'Артист поставлен в очередь.', 'run_id' => $run->id], 201);
    }

    public function enqueueListing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'artist_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'page_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $listingUrl = $this->normalizeArtistsListingUrl((string) $data['url']);

        if ($listingUrl === null) {
            throw ValidationException::withMessages([
                'url' => 'Укажите список артистов или жанровую страницу Muzofond, например /collections/artists или /popular/russian-rap.',
            ]);
        }

        $pageLimit = (int) ($data['page_limit'] ?? 0);
        $artistLimit = (int) $data['artist_limit'];
        $run = ParsingRun::query()->create([
            'kind' => 'artist_list',
            'source_url' => $listingUrl,
            'status' => 'queued',
            'stage' => 'queued',
            'options' => ['artist_limit' => $artistLimit, 'page_limit' => $pageLimit],
        ]);

        DiscoverMuzofondArtistListJob::dispatch($run->id, $listingUrl, $artistLimit, $pageLimit)
            ->onConnection('database')
            ->onQueue('tracks-parsing');

        return response()->json(['message' => 'Список поставлен в очередь на разбор.', 'run_id' => $run->id], 201);
    }

    private function normalizeArtistUrl(string $input): ?string
    {
        $input = trim($input);

        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $parts = parse_url($input);
            $host = mb_strtolower((string) ($parts['host'] ?? ''));

            if (! in_array($host, ['muzofond.fm', 'www.muzofond.fm'], true)) {
                return null;
            }

            $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));

            if (count($segments) < 3 || $segments[0] !== 'collections' || $segments[1] !== 'artists'
                || (isset($segments[3]) && ! ctype_digit((string) $segments[3])) || count($segments) > 4) {
                return null;
            }

            $input = rawurldecode((string) $segments[2]);
        } else {
            if (str_contains($input, '/') || str_contains($input, '?') || str_contains($input, '#')) {
                return null;
            }

            $input = rawurldecode($input);
        }

        $slug = trim($input);

        if ($slug === '' || mb_strlen($slug) > 200) {
            return null;
        }

        return 'https://muzofond.fm/collections/artists/' . rawurlencode($slug);
    }

    private function normalizeArtistsListingUrl(string $input): ?string
    {
        $parts = parse_url($input);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));

        if (! in_array($host, ['muzofond.fm', 'www.muzofond.fm'], true)
            || count($segments) > 3) {
            return null;
        }

        $isArtistDirectory = ($segments[0] ?? null) === 'collections'
            && ($segments[1] ?? null) === 'artists'
            && (! isset($segments[2]) || ctype_digit((string) $segments[2]));
        $isGenreDirectory = ($segments[0] ?? null) === 'popular'
            && isset($segments[1])
            && $segments[1] !== ''
            && (! isset($segments[2]) || ctype_digit((string) $segments[2]));

        if (! $isArtistDirectory && ! $isGenreDirectory) {
            return null;
        }

        return 'https://muzofond.fm/' . implode('/', array_slice($segments, 0, 2))
            . (isset($segments[2]) ? '/' . $segments[2] : '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function slugFromArtistUrl(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')), 'strlen'));

        return rawurldecode((string) ($segments[2] ?? ''));
    }
}
