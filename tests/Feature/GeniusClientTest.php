<?php

namespace Tests\Feature;

use App\Services\Genius\GeniusClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeniusClientTest extends TestCase
{
    public function test_apple_player_duration_requires_a_matching_real_apple_track_and_is_cached(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        $html = '<apple-music-player song_id="1783221" is_matched="true" '
            . 'preview_track="{&quot;apple_id&quot;:&quot;1104137649&quot;,&quot;duration&quot;:147.036}" '
            . 'apple_music_tracks="[{&quot;apple_id&quot;:&quot;1104137649&quot;,&quot;duration&quot;:147.036}]" '
            . 'unmatched_placeholder_track="{&quot;duration&quot;:0}"></apple-music-player>';
        Http::fake(['https://genius.test/songs/1783221/apple_music_player*' => Http::response($html)]);
        $client = new GeniusClient();
        $this->assertSame(147.036, $client->songAppleMusicDurationSeconds(1783221));
        $this->assertSame(147.036, $client->songAppleMusicDurationSeconds(1783221));
        Http::assertSentCount(1);
    }

    public function test_apple_player_placeholder_is_not_a_duration(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        Http::fake(['https://genius.test/songs/123/apple_music_player*' => Http::response(
            '<apple-music-player song_id="123" is_matched="false" unmatched_placeholder_track="{&quot;duration&quot;:0}"></apple-music-player>'
        )]);
        $this->assertNull((new GeniusClient())->songAppleMusicDurationSeconds(123));
    }

    public function test_song_search_results_are_reused_from_compact_cache_across_runs(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.cache_ttl', 3600);
        Cache::flush();
        Http::fake([
            'https://genius.test/api/search*' => Http::response([
                'response' => [
                    'hits' => [[
                        'result' => [
                            'id' => 501,
                            'title' => 'Papaoutai',
                            'artist_names' => 'Stromae',
                            'album' => ['name' => '√ (Racine carrée)'],
                        ],
                    ]],
                ],
            ]),
        ]);

        $firstClient = new GeniusClient();
        $firstClient->beginRun();
        $firstResults = $firstClient->searchSongs('Stromae Papaoutai');

        $secondClient = new GeniusClient();
        $secondClient->beginRun();
        $secondResults = $secondClient->searchSongs('Stromae Papaoutai');

        $this->assertSame($firstResults, $secondResults);
        $this->assertSame(501, $secondResults[0]['id']);
        Http::assertSentCount(1);
    }

    public function test_runtime_search_cache_is_bounded(): void
    {
        $client = new GeniusClient();
        $remember = (new \ReflectionClass($client))->getMethod('rememberRuntimeSearchPayload');

        for ($index = 0; $index < 300; $index++) {
            $remember->invoke($client, 'genius:test:' . $index, ['response' => ['hits' => []]]);
        }

        $keys = (new \ReflectionClass($client))->getProperty('runtimeSearchCacheKeys')->getValue($client);
        $runtimeCache = (new \ReflectionClass($client))->getProperty('runtimeCache')->getValue($client);

        $this->assertCount(256, $keys);
        $this->assertCount(256, $runtimeCache);
        $this->assertArrayNotHasKey('genius:test:0', $runtimeCache);
    }

    public function test_all_artist_songs_requests_featured_tracks_and_all_pages(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.access_token', 'test-token');
        config()->set('services.genius.songs_per_page', 50);
        config()->set('services.genius.max_pages', 5);
        Cache::flush();

        Http::fake([
            'https://genius.test/api/artists/77/songs*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                if ($page === 1) {
                    return Http::response([
                        'response' => [
                            'songs' => [
                                ['id' => 1, 'title' => 'First'],
                            ],
                            'next_page' => 2,
                        ],
                    ]);
                }

                return Http::response([
                    'response' => [
                        'songs' => [
                            ['id' => 2, 'title' => 'Second'],
                        ],
                        'next_page' => null,
                    ],
                ]);
            },
        ]);

        $songs = (new GeniusClient())->allArtistSongs(77);

        $this->assertSame([1, 2], array_column($songs, 'id'));

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/artists/77/songs')
                && in_array(($query['include_features'] ?? null), ['1', 'true', 'True', 1, true], true)
                && ($query['per_page'] ?? null) === '50';
        });
    }

    public function test_album_track_numbers_prefers_album_tracks_api_and_skips_noisy_album_html(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.access_token', 'test-token');
        config()->set('services.genius.songs_per_page', 50);
        config()->set('services.genius.max_pages', 5);
        Cache::flush();
        Http::preventStrayRequests();

        Http::fake([
            'https://genius.test/api/albums/29/tracks*' => Http::response([
                'response' => [
                    'tracks' => [
                        [
                            'number' => 17,
                            'song' => ['id' => 5070373, 'title' => 'Снег'],
                        ],
                        [
                            'number' => 18,
                            'song' => ['id' => 5889754, 'title' => 'Впадлу'],
                        ],
                    ],
                    'next_page' => null,
                ],
            ]),
        ]);

        $numbers = (new GeniusClient())->albumTrackNumbers(29, 'https://genius.test/albums/29');

        $this->assertSame([
            5070373 => 17,
            5889754 => 18,
        ], $numbers);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/albums/29/tracks'));
    }

    public function test_album_tracks_many_fetches_album_pages_as_one_bounded_batch(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.album_track_concurrency', 2);
        config()->set('services.genius.songs_per_page', 10);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            preg_match('~/albums/(\d+)/tracks~', $request->url(), $matches);
            $albumId = (int) ($matches[1] ?? 0);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $tracks = [];

            if ($albumId === 29 && $page === 1) {
                for ($number = 1; $number <= 10; $number++) {
                    $tracks[] = [
                        'number' => $number,
                        'song' => ['id' => 1028 + $number, 'title' => 'Track ' . $number],
                    ];
                }
            } else {
                $tracks[] = [
                    'number' => 11,
                    'song' => ['id' => 1039, 'title' => 'Track 11'],
                ];
            }

            return Http::response([
                'response' => [
                    'tracks' => $tracks,
                    'next_page' => $albumId === 29 && $page === 1 ? 2 : null,
                ],
            ]);
        });

        $tracksByAlbum = (new GeniusClient())->albumTracksMany([29, 30, 29, 0]);

        $this->assertCount(11, $tracksByAlbum[29]);
        $this->assertCount(1, $tracksByAlbum[30]);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/albums/29/tracks'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/albums/30/tracks'));
    }

    public function test_all_artist_songs_returns_empty_array_on_404_instead_of_throwing(): void
    {
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.access_token', 'test-token');
        Cache::flush();

        Http::fake([
            'https://genius.test/api/artists/999/songs*' => Http::response([], 404),
        ]);

        $songs = (new GeniusClient())->allArtistSongs(999);

        $this->assertSame([], $songs);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/artists/999/songs'));
    }
}
