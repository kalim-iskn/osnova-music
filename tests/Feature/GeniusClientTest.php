<?php

namespace Tests\Feature;

use App\Services\Genius\GeniusClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeniusClientTest extends TestCase
{
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
}
