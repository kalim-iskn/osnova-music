<?php

namespace App\Jobs;

use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\TrackParsing\MuzofondTrackParser;
use App\Models\ParsingRun;
use App\Models\ParsingTask;
use App\Models\ParsingTaskTrack;
use App\Support\QueueWorkerFatalMonitor;
use App\Services\TrackParsing\MuzofondAudioIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use RuntimeException;

class ParseMuzofondArtistJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    /**
     * @var int[]
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $artistUrl,
        public int $pageLimit = 0,
        public bool $debugMatching = false,
        public bool $debugParsing = false,
        public ?int $taskId = null,
    ) {
    }

    public function handle(
        MuzofondTrackParser $muzofondParser,
        GeniusCatalogSyncService $geniusCatalogSyncService,
    ): void {
        $task = $this->taskId ? ParsingTask::query()->find($this->taskId) : null;
        $previousGeniusMetrics = $this->metricTotals(is_array($task?->genius_metrics) ? $task->genius_metrics : []);
        $fallbackSamplingState = $task && is_array($task->genius_metrics)
            ? (array) ($task->genius_metrics['fallback_sampling'] ?? [])
            : [];
        $metricsBeforeCurrentPage = $this->emptyMetricTotals();
        $currentPageMetricsIncluded = false;

        $geniusCatalogSyncService->setFallbackSamplingContext(
            $task ? (string) $task->id : null,
            $fallbackSamplingState,
        );

        if ($task) {
            $task->forceFill([
                'status' => 'running',
                'stage' => 'fetching_muzofond',
                'attempts' => $this->attempts(),
                'started_at' => $task->started_at ?? now(),
                'finished_at' => null,
                'error' => null,
                'muzofond_duration_ms' => null,
                'genius_metrics' => $this->cumulativeMetrics(
                    $previousGeniusMetrics,
                    $this->emptyMetricTotals(),
                    $this->emptyMetricTotals(),
                    $fallbackSamplingState,
                    $this->attempts(),
                ),
            ])->save();
            $task->run()->update(['status' => 'running', 'stage' => 'processing_artists', 'started_at' => $task->run->started_at ?? now()]);
        }

        if ($this->debugMatching) {
            $geniusCatalogSyncService->setNeedDebug(true);
        }

        if ($task) {
            $geniusCatalogSyncService->setLogContext([
                'parsing_run_id' => (int) $task->parsing_run_id,
                'parsing_task_id' => (int) $task->id,
            ]);
        }

        if ($this->debugParsing) {
            $muzofondParser->setNeedDebug(true);
        }

        // Keep a small reserve so a PHP memory fatal can still persist retry
        // state and release the database-queue reservation before this process exits.
        $shutdownMemoryReserve = str_repeat('R', 1024 * 1024);
        $taskId = $task?->id;
        $runId = $task?->parsing_run_id;
        $artistUrl = $this->artistUrl;
        QueueWorkerFatalMonitor::watch(function (array $error) use (
            $taskId,
            $runId,
            $artistUrl,
            &$geniusCatalogSyncService,
            &$metricsBeforeCurrentPage,
            &$currentPageMetricsIncluded,
            &$fallbackSamplingState,
            &$shutdownMemoryReserve,
            $previousGeniusMetrics,
        ): void {
            $shutdownMemoryReserve = '';
            $attempt = $this->attempts();
            $message = sprintf(
                'PHP fatal error in artist parser (type %s): %s at %s:%s',
                $error['type'] ?? 'unknown',
                $error['message'] ?? 'unknown fatal error',
                $error['file'] ?? 'unknown file',
                $error['line'] ?? 'unknown line',
            );

            try {
                $currentMetrics = $currentPageMetricsIncluded
                    ? $this->emptyMetricTotals()
                    : $geniusCatalogSyncService->geniusMetrics();
                $attemptMetrics = $this->mergeMetricTotals($metricsBeforeCurrentPage, $currentMetrics);
                $cumulativeMetrics = $this->cumulativeMetrics(
                    $previousGeniusMetrics,
                    $attemptMetrics,
                    $this->emptyMetricTotals(),
                    $fallbackSamplingState,
                    $attempt,
                );
                $geniusCatalogSyncService = null;

                $currentTask = $taskId ? ParsingTask::query()->find($taskId) : null;
                $retryScheduled = false;
                if ($currentTask?->status === 'completed' && $currentTask->finished_at !== null) {
                    $currentTask->forceFill(['genius_metrics' => $cumulativeMetrics])->save();
                    $this->delete();
                } elseif ($attempt >= $this->tries) {
                    $currentTask?->forceFill([
                        'attempts' => $attempt,
                        'genius_metrics' => $cumulativeMetrics,
                    ])->save();
                    $this->fail(new RuntimeException($message));
                } else {
                    $currentTask?->forceFill([
                        'status' => 'queued',
                        'stage' => 'retry_wait',
                        'attempts' => $attempt,
                        'error' => mb_substr($message, 0, 6000),
                        'current_track' => null,
                        'finished_at' => null,
                        'genius_metrics' => $cumulativeMetrics,
                    ])->save();
                    $currentTask?->run?->refreshFromTasks();

                    $backoffIndex = min(max(0, $attempt - 1), count($this->backoff) - 1);
                    $this->release((int) ($this->backoff[$backoffIndex] ?? 60));
                    $retryScheduled = true;
                }

                try {
                    Log::critical('Queued Muzofond artist worker terminated with a fatal PHP error; retry state was recorded.', [
                        'artist_url' => $artistUrl,
                        'parsing_run_id' => $runId,
                        'parsing_task_id' => $taskId,
                        'attempt' => $attempt,
                        'will_retry' => $retryScheduled,
                        'php_error_type' => $error['type'] ?? null,
                        'php_error' => $error['message'] ?? 'unknown fatal error',
                        'php_file' => $error['file'] ?? null,
                        'php_line' => $error['line'] ?? null,
                        'peak_memory_bytes' => memory_get_peak_usage(true),
                    ]);
                } catch (Throwable $loggingException) {
                    error_log(sprintf(
                        'Recovered queued Muzofond artist task %s after fatal PHP error; log write failed: %s',
                        $taskId ?? 'untracked',
                        $loggingException->getMessage(),
                    ));
                }
            } catch (Throwable $recoveryException) {
                error_log(sprintf(
                    'Failed to recover queued Muzofond artist task %s on attempt %d: %s; fatal: %s',
                    $taskId ?? 'untracked',
                    $attempt,
                    $recoveryException->getMessage(),
                    $error['message'] ?? 'unknown fatal error',
                ));
            }
        });

        $startedAt = microtime(true);
        try {
            $muzofondStartedAt = hrtime(true);
            $pages = $muzofondParser->parse($this->artistUrl, 0, $this->pageLimit);
            $muzofondDurationMs = (int) round((hrtime(true) - $muzofondStartedAt) / 1_000_000);

            if ($pages === []) {
                throw new RuntimeException('На странице Muzofond не найден артист.');
            }

            $allCheckpointRows = $task
                ? ParsingTaskTrack::query()->where('parsing_task_id', $task->id)->get(['source_key', 'matched', 'completed'])
                : collect();
            $checkpointRows = $allCheckpointRows->where('completed', true);
            $completedOutcomes = $checkpointRows
                ->mapWithKeys(fn (ParsingTaskTrack $row): array => [(string) $row->source_key => (bool) $row->matched])
                ->all();
            $allCheckpointKeys = $allCheckpointRows
                ->mapWithKeys(fn (ParsingTaskTrack $row): array => [(string) $row->source_key => true])
                ->all();
            $geniusCatalogSyncService->setCompletedTrackOutcomes($completedOutcomes);

            foreach ($pages as $page) {
                $pageKeys = collect($page->tracks)
                    ->map(fn ($track): string => MuzofondAudioIdentity::checkpointKey(
                        $track->audioUrl,
                        $track->audioFileId,
                        $track->title,
                        $track->durationSeconds,
                    ))
                    ->unique()
                    ->values()
                    ->all();
                $pageKeySet = array_fill_keys($pageKeys, true);
                $pageOutcomes = array_intersect_key($completedOutcomes, $pageKeySet);
                $pageMatchedCount = count(array_filter($pageOutcomes));
                $pageUnmatchedCount = count($pageOutcomes) - $pageMatchedCount;
                $savedCheckpointCount = count(array_intersect_key($allCheckpointKeys, $pageKeySet));

                if ($task) {
                    $task->forceFill([
                        'artist_name' => $page->artistName,
                        'artist_slug' => $page->artistSlug,
                        'tracks_total' => count($page->tracks),
                        'tracks_processed' => count($pageOutcomes),
                        'matched_tracks' => $pageMatchedCount,
                        'unmatched_tracks' => $pageUnmatchedCount,
                        'saved_tracks' => $savedCheckpointCount,
                        'current_track' => null,
                        'stage' => 'matching_genius',
                        'muzofond_duration_ms' => $muzofondDurationMs,
                    ])->save();

                    $geniusCatalogSyncService->setProgressCallback(function (array $progress) use (
                        $task,
                        &$savedCheckpointCount,
                        $previousGeniusMetrics,
                        &$metricsBeforeCurrentPage,
                        &$fallbackSamplingState,
                    ): void {
                        $checkpoint = $progress['_track_checkpoint'] ?? null;
                        unset($progress['_track_checkpoint']);

                        if (is_array($checkpoint) && is_string($checkpoint['source_key'] ?? null)) {
                            $record = ParsingTaskTrack::query()->firstOrNew([
                                'parsing_task_id' => $task->id,
                                'source_key' => $checkpoint['source_key'],
                            ]);
                            $isNew = ! $record->exists;
                            $record->fill([
                                'track_title' => $checkpoint['track_title'] ?? null,
                                'matched' => (bool) ($checkpoint['matched'] ?? false),
                                'completed' => (bool) ($checkpoint['completed'] ?? true),
                            ])->save();

                            if ($isNew) {
                                $savedCheckpointCount++;
                            }

                            $progress['saved_tracks'] = $savedCheckpointCount;
                        }

                        $metrics = (array) ($progress['genius_metrics'] ?? []);
                        $fallback = (array) ($metrics['fallback_sampling'] ?? []);
                        $fallbackSamplingState = $fallback;
                        unset($metrics['fallback_sampling']);
                        $progress['genius_metrics'] = $this->cumulativeMetrics(
                            $previousGeniusMetrics,
                            $metricsBeforeCurrentPage,
                            $metrics,
                            $fallback,
                            $this->attempts(),
                        );

                        $task->forceFill($progress)->save();
                    });
                }

                $currentPageMetricsIncluded = false;
                $result = $geniusCatalogSyncService->syncArtistPage($page);
                $metricsBeforeCurrentPage = $this->mergeMetricTotals(
                    $metricsBeforeCurrentPage,
                    $geniusCatalogSyncService->geniusMetrics(),
                );
                $currentPageMetricsIncluded = true;

                if ($task) {
                    $task->forceFill([
                        'genius_metrics' => $this->cumulativeMetrics(
                            $previousGeniusMetrics,
                            $metricsBeforeCurrentPage,
                            $this->emptyMetricTotals(),
                            $fallbackSamplingState,
                            $this->attempts(),
                        ),
                    ])->save();
                }

                if ($task) {
                    $finalCheckpointRows = ParsingTaskTrack::query()
                        ->where('parsing_task_id', $task->id)
                        ->where('completed', true)
                        ->get(['source_key', 'matched']);
                    $finalOutcomes = $finalCheckpointRows
                        ->mapWithKeys(fn (ParsingTaskTrack $row): array => [(string) $row->source_key => (bool) $row->matched])
                        ->all();
                    $finalPageOutcomes = array_intersect_key($finalOutcomes, $pageKeySet);
                    $finalMatchedCount = count(array_filter($finalPageOutcomes));

                    if (count($finalPageOutcomes) < count($pageKeySet)) {
                        throw new RuntimeException(sprintf(
                            'Сохранены не все аудиофайлы: контрольные точки есть для %d из %d.',
                            count($finalPageOutcomes),
                            count($pageKeySet),
                        ));
                    }

                    $task->forceFill([
                        'status' => 'completed',
                        'stage' => 'completed',
                        'tracks_total' => count($page->tracks),
                        'tracks_processed' => count($page->tracks),
                        'matched_tracks' => $finalMatchedCount,
                        'unmatched_tracks' => count($finalPageOutcomes) - $finalMatchedCount,
                        'saved_tracks' => count($finalPageOutcomes),
                        'current_track' => null,
                        'finished_at' => now(),
                    ])->save();
                    $task->run->refreshFromTasks();
                }

                Log::info('Queued muzofond artist parse finished.', [
                    'artist_url' => $this->artistUrl,
                    'artist' => $page->artistName,
                    'artist_slug' => $page->artistSlug,
                    'muzofond_track_count' => count($page->tracks),
                    'saved_artists' => $result['artists'],
                    'saved_albums' => $result['albums'],
                    'saved_tracks' => $result['tracks'],
                    'matched_tracks' => $result['matched_tracks'],
                    'unmatched_tracks' => $result['unmatched_tracks'],
                    'genius_matched' => $result['genius_matched'],
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }
            QueueWorkerFatalMonitor::clear();
        } catch (Throwable $exception) {
            $currentMetrics = $currentPageMetricsIncluded
                ? $this->emptyMetricTotals()
                : $geniusCatalogSyncService->geniusMetrics();
            $attemptMetrics = $this->mergeMetricTotals($metricsBeforeCurrentPage, $currentMetrics);
            $cumulativeMetrics = $this->cumulativeMetrics(
                $previousGeniusMetrics,
                $attemptMetrics,
                $this->emptyMetricTotals(),
                $fallbackSamplingState,
                $this->attempts(),
            );

            if ($task?->status === 'completed' && $task->finished_at !== null) {
                QueueWorkerFatalMonitor::clear();
                $this->delete();

                return;
            }

            if ($task) {
                $task->forceFill([
                    'status' => 'queued',
                    'stage' => $this->attempts() >= $this->tries ? 'failed' : 'retry_wait',
                    'error' => mb_substr($exception->getMessage(), 0, 6000),
                    'current_track' => null,
                    'finished_at' => $this->attempts() >= $this->tries ? now() : null,
                    'genius_metrics' => $cumulativeMetrics,
                ])->save();
                $task->run->refreshFromTasks();
            }

            QueueWorkerFatalMonitor::clear();

            throw $exception;
        }
    }

    /** @return array{requests:int,retries:int,duration_ms:int,groups:array<string,array<string,mixed>>} */
    private function emptyMetricTotals(): array
    {
        return ['requests' => 0, 'retries' => 0, 'duration_ms' => 0, 'groups' => []];
    }

    /** @param array<string, mixed> $metrics
     *  @return array{requests:int,retries:int,duration_ms:int,groups:array<string,array<string,mixed>>}
     */
    private function metricTotals(array $metrics): array
    {
        $totals = $this->emptyMetricTotals();
        $totals['requests'] = max(0, (int) ($metrics['requests'] ?? 0));
        $totals['retries'] = max(0, (int) ($metrics['retries'] ?? 0));
        $totals['duration_ms'] = max(0, (int) ($metrics['duration_ms'] ?? 0));

        foreach ((array) ($metrics['groups'] ?? []) as $group => $groupMetrics) {
            if (! is_string($group) || ! is_array($groupMetrics)) {
                continue;
            }

            $totals['groups'][$group] = [
                'requests' => max(0, (int) ($groupMetrics['requests'] ?? 0)),
                'retries' => max(0, (int) ($groupMetrics['retries'] ?? 0)),
                'duration_ms' => max(0, (int) ($groupMetrics['duration_ms'] ?? 0)),
                'errors' => max(0, (int) ($groupMetrics['errors'] ?? 0)),
                'statuses' => array_map('intval', (array) ($groupMetrics['statuses'] ?? [])),
            ];
        }

        return $totals;
    }

    /** @param array<string, mixed> $left
     *  @param array<string, mixed> $right
     *  @return array{requests:int,retries:int,duration_ms:int,groups:array<string,array<string,mixed>>}
     */
    private function mergeMetricTotals(array $left, array $right): array
    {
        $merged = $this->metricTotals($left);
        $increment = $this->metricTotals($right);

        foreach (['requests', 'retries', 'duration_ms'] as $key) {
            $merged[$key] += $increment[$key];
        }

        foreach ($increment['groups'] as $group => $groupMetrics) {
            $merged['groups'][$group] ??= [
                'requests' => 0,
                'retries' => 0,
                'duration_ms' => 0,
                'errors' => 0,
                'statuses' => [],
            ];

            foreach (['requests', 'retries', 'duration_ms', 'errors'] as $key) {
                $merged['groups'][$group][$key] += $groupMetrics[$key];
            }

            foreach ($groupMetrics['statuses'] as $status => $count) {
                $merged['groups'][$group]['statuses'][$status] =
                    ($merged['groups'][$group]['statuses'][$status] ?? 0) + $count;
            }
        }

        return $merged;
    }

    /** @param array<string, mixed> $previousAttempts
     *  @param array<string, mixed> $completedPages
     *  @param array<string, mixed> $currentPage
     *  @param array<string, mixed> $fallbackSampling
     *  @return array<string, mixed>
     */
    private function cumulativeMetrics(
        array $previousAttempts,
        array $completedPages,
        array $currentPage,
        array $fallbackSampling,
        ?int $attempt = null,
    ): array {
        $metrics = $this->mergeMetricTotals(
            $this->mergeMetricTotals($previousAttempts, $completedPages),
            $currentPage,
        );
        if ($fallbackSampling !== []) {
            $metrics['fallback_sampling'] = $fallbackSampling;
        }

        if ($attempt !== null) {
            $metrics['attempts_included'] = max(1, $attempt);
        }

        return $metrics;
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->taskId) {
            return;
        }

        $task = ParsingTask::query()->find($this->taskId);

        if (! $task) {
            return;
        }

        $task->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Задание завершилось ошибкой.', 0, 6000),
            'current_track' => null,
            'finished_at' => now(),
        ])->save();
        $task->run->refreshFromTasks();

        Log::error('Queued Muzofond artist task exhausted its retry attempts.', [
            'artist_url' => $this->artistUrl,
            'parsing_run_id' => (int) $task->parsing_run_id,
            'parsing_task_id' => (int) $task->id,
            'attempts' => (int) $task->attempts,
            'exception_class' => $exception ? $exception::class : null,
            'exception' => $exception?->getMessage(),
            'saved_source_tracks' => (int) $task->saved_tracks,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    }
}
