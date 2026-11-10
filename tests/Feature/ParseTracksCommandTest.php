<?php

namespace Tests\Feature;

use App\Jobs\ParseMuzofondArtistJob;
use App\Models\ParsingRun;
use App\Models\ParsingTask;
use App\Models\ParsingTaskTrack;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\Genius\GeniusClient;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\MuzofondAudioIdentity;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseTracksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_artist_job_persists_source_track_checkpoints_and_resumes_without_duplicates(): void
    {
        $run = ParsingRun::query()->create([
            'kind' => 'artist',
            'source_url' => 'https://muzofond.fm/collections/artists/checkpoint-artist',
            'status' => 'queued',
            'stage' => 'queued',
            'total_tasks' => 1,
        ]);
        $task = ParsingTask::query()->create([
            'parsing_run_id' => $run->id,
            'artist_url' => 'https://muzofond.fm/collections/artists/checkpoint-artist',
            'status' => 'queued',
            'stage' => 'queued',
        ]);
        $page = new ParsedArtistPage(
            artistName: 'Checkpoint Artist',
            artistSlug: 'checkpoint-artist',
            imageUrl: null,
            tracks: [new ParsedTrack(
                title: 'Saved fallback source',
                durationSeconds: 181,
                audioUrl: 'https://audio.test/987654.mp3',
                albumTitle: null,
                artistNames: ['Checkpoint Artist'],
                audioFileId: '987654',
            )],
        );
        $parser = new class($page) extends MuzofondTrackParser {
            public function __construct(private readonly ParsedArtistPage $page) {}

            public function parse(string $url, int $artistLimit = 0, int $pageLimit = 0): array
            {
                return [$this->page];
            }
        };
        $client = new class() extends GeniusClient {
            public int $songSearchCalls = 0;

            public function __construct() {}

            public function searchArtist(string $query): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                $this->songSearchCalls++;

                return [];
            }
        };
        $job = new ParseMuzofondArtistJob($task->artist_url, taskId: $task->id);

        $job->handle($parser, new GeniusCatalogSyncService($client));
        $job->handle($parser, new GeniusCatalogSyncService($client));

        $checkpoint = ParsingTaskTrack::query()->where('parsing_task_id', $task->id)->firstOrFail();
        $task->refresh();

        $this->assertTrue($checkpoint->completed);
        $this->assertFalse($checkpoint->matched);
        $this->assertSame(1, ParsingTaskTrack::query()->where('parsing_task_id', $task->id)->count());
        $this->assertSame(1, $task->saved_tracks);
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, \App\Models\Track::query()->count());
        $this->assertSame(0, $client->songSearchCalls);
    }

    public function test_tracks_parse_queues_one_job_per_artist_for_listing_urls(): void
    {
        Queue::fake();

        $this->app->instance(MuzofondTrackParser::class, new class() extends MuzofondTrackParser
        {
            public function isArtistsListing(string $url): bool
            {
                return true;
            }

            public function artistUrls(string $url, int $artistLimit = 0): array
            {
                return [
                    'https://muzofond.fm/collections/artists/artist-one',
                    'https://muzofond.fm/collections/artists/artist-two',
                ];
            }
        });

        $this->app->instance(
            GeniusCatalogSyncService::class,
            new GeniusCatalogSyncService(new class() extends GeniusClient
            {
                public function __construct()
                {
                }
            })
        );

        $this->artisan('tracks:parse', [
            'parser' => 'muzofond',
            'url' => 'https://muzofond.fm/collections/artists',
            '--artist-limit' => 2,
            '--page-limit' => 3,
        ])
            ->expectsOutputToContain('Queued artist jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(ParseMuzofondArtistJob::class, 2);
        Queue::assertPushed(ParseMuzofondArtistJob::class, function (ParseMuzofondArtistJob $job): bool {
            return $job->artistUrl === 'https://muzofond.fm/collections/artists/artist-one'
                && $job->pageLimit === 3
                && $job->connection === 'database'
                && $job->queue === 'tracks-parsing';
        });
        Queue::assertPushed(ParseMuzofondArtistJob::class, function (ParseMuzofondArtistJob $job): bool {
            return $job->artistUrl === 'https://muzofond.fm/collections/artists/artist-two'
                && $job->pageLimit === 3
                && $job->connection === 'database'
                && $job->queue === 'tracks-parsing';
        });
    }

    public function test_queued_artist_job_accumulates_genius_metrics_across_retries(): void
    {
        $run = ParsingRun::query()->create([
            'kind' => 'artist',
            'source_url' => 'https://muzofond.fm/collections/artists/metrics-artist',
            'status' => 'queued',
            'stage' => 'queued',
            'total_tasks' => 1,
        ]);
        $task = ParsingTask::query()->create([
            'parsing_run_id' => $run->id,
            'artist_url' => 'https://muzofond.fm/collections/artists/metrics-artist',
            'status' => 'queued',
            'stage' => 'queued',
        ]);
        $track = new ParsedTrack(
            title: 'Metrics source track',
            durationSeconds: 181,
            audioUrl: 'https://audio.test/metrics-987.mp3',
            albumTitle: null,
            artistNames: ['Metrics Artist'],
            audioFileId: 'metrics-987',
        );
        $page = new ParsedArtistPage(
            artistName: 'Metrics Artist',
            artistSlug: 'metrics-artist',
            imageUrl: null,
            tracks: [$track],
        );
        $parser = new class($page) extends MuzofondTrackParser {
            public function __construct(private readonly ParsedArtistPage $page) {}

            public function parse(string $url, int $artistLimit = 0, int $pageLimit = 0): array
            {
                return [$this->page];
            }
        };
        $service = new class(new GeniusClient()) extends GeniusCatalogSyncService {
            private ?\Closure $progress = null;

            private int $attempt = 0;

            private array $currentMetrics = [];

            public function setFallbackSamplingContext(?string $seed, array $state = []): void {}

            public function setLogContext(array $context): void {}

            public function setCompletedTrackOutcomes(array $outcomes): void {}

            public function setProgressCallback(?callable $callback): void
            {
                $this->progress = $callback === null ? null : \Closure::fromCallable($callback);
            }

            public function syncArtistPage(ParsedArtistPage $page): array
            {
                $this->attempt++;
                $requestCount = $this->attempt === 1 ? 3 : 2;
                $this->currentMetrics = [
                    'requests' => $requestCount,
                    'retries' => 0,
                    'duration_ms' => $requestCount * 100,
                    'groups' => [
                        'song_search' => [
                            'requests' => $requestCount,
                            'retries' => 0,
                            'duration_ms' => $requestCount * 100,
                            'errors' => 0,
                            'statuses' => ['200' => $requestCount],
                        ],
                    ],
                ];

                ($this->progress)([
                    '_track_checkpoint' => [
                        'source_key' => MuzofondAudioIdentity::checkpointKey(
                            $page->tracks[0]->audioUrl,
                            $page->tracks[0]->audioFileId,
                            $page->tracks[0]->title,
                            $page->tracks[0]->durationSeconds,
                        ),
                        'track_title' => $page->tracks[0]->title,
                        'matched' => false,
                        'completed' => true,
                    ],
                    'genius_metrics' => $this->currentMetrics,
                ]);

                if ($this->attempt === 1) {
                    throw new \RuntimeException('Simulated retry after partial progress.');
                }

                return [
                    'artists' => 0,
                    'albums' => 0,
                    'tracks' => 1,
                    'matched_tracks' => 0,
                    'unmatched_tracks' => 1,
                    'genius_matched' => false,
                    'genius_unavailable' => false,
                ];
            }

            public function geniusMetrics(): array
            {
                return $this->currentMetrics;
            }
        };
        $job = new ParseMuzofondArtistJob($task->artist_url, taskId: $task->id);

        try {
            $job->handle($parser, $service);
            $this->fail('The first attempt should request a retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated retry after partial progress.', $exception->getMessage());
        }

        $task->refresh();
        $this->assertSame(3, $task->genius_metrics['requests']);
        $this->assertSame('retry_wait', $task->stage);

        $job->handle($parser, $service);

        $task->refresh();
        $this->assertSame(5, $task->genius_metrics['requests']);
        $this->assertSame(500, $task->genius_metrics['duration_ms']);
        $this->assertSame(5, $task->genius_metrics['groups']['song_search']['statuses']['200']);
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, ParsingTaskTrack::query()->where('parsing_task_id', $task->id)->count());
    }
}
