<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeniusCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_artist_page_falls_back_to_search_and_prefers_oldest_non_demo_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Original Winter',
            'slug' => 'original-winter',
            'cover_image_url' => null,
            'release_date' => '2020-01-10',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [
                    [
                        'id' => 102,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Р РЋР Р…Р ВµР С– Demo'],
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 101,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Original Winter'],
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    ],
                    [
                        'id' => 103,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Original Winter (Deluxe)'],
                        'release_date_components' => ['year' => 2022, 'month' => 5, 'day' => 12],
                    ],
                ];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    101 => [
                        'id' => 101,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 201, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/201'],
                        'release_date' => '2020-01-10',
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                        'song_art_image_url' => 'https://img.test/original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/101',
                    ],
                    102 => [
                        'id' => 102,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 202, 'name' => 'Р РЋР Р…Р ВµР С– Demo', 'url' => 'https://genius.test/albums/202'],
                        'release_date' => '2019-12-01',
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/demo.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/102',
                    ],
                    103 => [
                        'id' => 103,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 203, 'name' => 'Original Winter (Deluxe)', 'url' => 'https://genius.test/albums/203'],
                        'release_date' => '2022-05-12',
                        'release_date_components' => ['year' => 2022, 'month' => 5, 'day' => 12],
                        'song_art_image_url' => 'https://img.test/deluxe.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 200],
                        'url' => 'https://genius.test/songs/103',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    201 => [
                        'id' => 201,
                        'name' => 'Original Winter',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2020-01-10',
                        'cover_art_thumbnail_url' => 'https://img.test/original-cover.jpg',
                    ],
                    202 => [
                        'id' => 202,
                        'name' => 'Р РЋР Р…Р ВµР С– Demo',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2019-12-01',
                        'cover_art_thumbnail_url' => 'https://img.test/demo-cover.jpg',
                    ],
                    203 => [
                        'id' => 203,
                        'name' => 'Original Winter (Deluxe)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2022-05-12',
                        'cover_art_thumbnail_url' => 'https://img.test/deluxe-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    201 => [101 => 2],
                    202 => [102 => 1],
                    203 => [103 => 7],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР Р…Р ВµР С–',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/sneg.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(101, $track->genius_id);
        $this->assertNotSame('', (string) $track->title);
        $this->assertSame('Original Winter', $track->album?->title);
        $this->assertSame('2020-01-10', $track->album?->release_date?->format('Y-m-d'));
    }

    public function test_sync_artist_page_accepts_featured_tracks_even_when_primary_artist_differs(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 201,
                    'title' => 'Р РЋР Р…Р ВµР С–',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'featured_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['name' => 'Original Winter'],
                    'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 201) {
                    return null;
                }

                return [
                    'id' => 201,
                    'title' => 'Р РЋР Р…Р ВµР С–',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'featured_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['id' => 301, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/301'],
                    'release_date' => '2020-01-10',
                    'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    'song_art_image_url' => 'https://img.test/feature.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 120],
                    'url' => 'https://genius.test/songs/201',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 301) {
                    return null;
                }

                return [
                    'id' => 301,
                    'name' => 'Original Winter',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'release_date' => '2020-01-10',
                    'cover_art_thumbnail_url' => 'https://img.test/feature-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [201 => 3];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР Р…Р ВµР С–',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/sneg-feature.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);

        $track = Track::query()->with(['artist', 'artists'])->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(201, $track->genius_id);
        $this->assertSame('Other Artist', $track->artist?->name);
        $this->assertEqualsCanonicalizing(['Other Artist', 'Mayot'], $track->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_prefers_album_backed_detail_over_albumless_duplicate(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Original Winter',
            'slug' => 'original-winter-priority',
            'cover_image_url' => null,
            'release_date' => '2020-01-10',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 401,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => null,
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 402,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => null,
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    401 => [
                        'id' => 401,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => null,
                        'release_date' => '2019-12-01',
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/snow-single.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/401',
                    ],
                    402 => [
                        'id' => 402,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 501, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/501'],
                        'release_date' => '2020-01-10',
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                        'song_art_image_url' => 'https://img.test/snow-album.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/402',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'name' => 'Original Winter',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-01-10',
                    'cover_art_thumbnail_url' => 'https://img.test/original-winter-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [402 => 5];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Snow',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/snow-album-priority.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(402, $track->genius_id);
        $this->assertSame('Original Winter', $track->album?->title);
    }

    public function test_sync_artist_page_prefers_original_album_over_live_release_when_muzofond_album_and_year_match_original(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Cheese',
            'slug' => 'cheese-original',
            'cover_image_url' => null,
            'release_date' => '2010-01-01',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 701,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 702,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    701 => [
                        'id' => 701,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 801, 'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))', 'url' => 'https://genius.test/albums/801'],
                        'release_date' => '2015-12-01',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 800],
                        'url' => 'https://genius.test/songs/701',
                    ],
                    702 => [
                        'id' => 702,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 802, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/802'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/cheese.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 600],
                        'url' => 'https://genius.test/songs/702',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    801 => [
                        'id' => 801,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-01',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    802 => [
                        'id' => 802,
                        'name' => 'Cheese',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-01-01',
                        'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    801 => [701 => 9],
                    802 => [702 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse',
                    durationSeconds: 207,
                    audioUrl: 'https://audio.test/alors-original.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(702, $track->genius_id);
        $this->assertSame('Cheese', $track->album?->title);
    }

    public function test_sync_artist_page_falls_back_to_album_tracks_when_artist_song_feed_misses_original_album_version(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Cheese',
            'slug' => 'cheese-album-tracks',
            'cover_image_url' => null,
            'release_date' => '2010-06-14',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 801,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Alors On Danse - EP'],
                        'release_date_components' => ['year' => 2010, 'month' => 9, 'day' => 2],
                    ],
                    [
                        'id' => 802,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))'],
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 901,
                        'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))',
                        'url' => 'https://genius.test/albums/901',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-09-26',
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                    ],
                    [
                        'id' => 902,
                        'name' => 'Cheese',
                        'url' => 'https://genius.test/albums/902',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-06-14',
                        'release_date_components' => ['year' => 2010, 'month' => 6, 'day' => 14],
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return match ($albumId) {
                    901 => [[
                        'number' => 9,
                        'song' => [
                            'id' => 802,
                            'title' => 'Alors On Danse',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    902 => [[
                        'number' => 1,
                        'song' => [
                            'id' => 803,
                            'title' => 'Alors On Danse',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    default => [],
                };
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    801 => [
                        'id' => 801,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Alors On Danse - EP', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-09-02',
                        'release_date_components' => ['year' => 2010, 'month' => 9, 'day' => 2],
                        'song_art_image_url' => 'https://img.test/ep.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/801',
                    ],
                    802 => [
                        'id' => 802,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 901, 'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))', 'url' => 'https://genius.test/albums/901'],
                        'release_date' => '2009-09-26',
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/802',
                    ],
                    803 => [
                        'id' => 803,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 902, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/902'],
                        'release_date' => '2010-06-14',
                        'release_date_components' => ['year' => 2010, 'month' => 6, 'day' => 14],
                        'song_art_image_url' => 'https://img.test/cheese.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 350],
                        'url' => 'https://genius.test/songs/803',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    901 => [
                        'id' => 901,
                        'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-09-26',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    902 => [
                        'id' => 902,
                        'name' => 'Cheese',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-06-14',
                        'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                    ],
                    903 => [
                        'id' => 903,
                        'name' => 'Alors On Danse - EP',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-09-02',
                        'cover_art_thumbnail_url' => 'https://img.test/ep-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    901 => [802 => 9],
                    902 => [803 => 1],
                    903 => [801 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse',
                    durationSeconds: 207,
                    audioUrl: 'https://audio.test/alors-from-album-tracks.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(803, $track->genius_id);
        $this->assertSame('Cheese', $track->album?->title);
    }

    public function test_sync_artist_page_reuses_existing_track_by_genius_id_when_original_album_match_is_found_later(): void
    {
        $existingArtist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'genius_id' => 77,
        ]);
        $liveAlbum = Album::query()->create([
            'artist_id' => $existingArtist->id,
            'title' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
            'slug' => 'racine-carree-live',
            'genius_id' => 491687,
            'release_date' => '2015-12-11',
        ]);
        $originalAlbum = Album::query()->create([
            'artist_id' => $existingArtist->id,
            'title' => 'РІв‚¬С™',
            'slug' => 'racine-carree-original',
            'genius_id' => null,
            'release_date' => '2013-08-16',
        ]);
        $existingTrack = Track::query()->create([
            'artist_id' => $existingArtist->id,
            'album_id' => $liveAlbum->id,
            'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
            'duration_seconds' => 216,
            'audio_url' => 'https://audio.test/old-tous.mp3',
            'genius_id' => 203940,
        ]);
        $existingTrack->artists()->sync([$existingArtist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 139735,
                        'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Les leР“В§ons'],
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                    ],
                    [
                        'id' => 203941,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 35659,
                        'name' => 'Les leР“В§ons',
                        'url' => 'https://genius.test/albums/35659',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-01-31',
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                    ],
                    [
                        'id' => 40748,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e)',
                        'url' => 'https://genius.test/albums/40748',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    ],
                    [
                        'id' => 491687,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'url' => 'https://genius.test/albums/491687',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-11',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return match ($albumId) {
                    35659 => [[
                        'number' => 24,
                        'song' => [
                            'id' => 139735,
                            'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    40748 => [[
                        'number' => 8,
                        'song' => [
                            'id' => 203940,
                            'title' => 'Tous les mР“Р„mes',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    491687 => [[
                        'number' => 8,
                        'song' => [
                            'id' => 203941,
                            'title' => 'Tous les mР“Р„mes',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    default => [],
                };
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    139735 => [
                        'id' => 139735,
                        'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 35659, 'name' => 'Les leР“В§ons', 'url' => 'https://genius.test/albums/35659'],
                        'release_date' => '2009-01-31',
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                        'song_art_image_url' => 'https://img.test/lesson.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 150],
                        'url' => 'https://genius.test/songs/139735',
                    ],
                    203940 => [
                        'id' => 203940,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 40748, 'name' => 'РІв‚¬С™', 'url' => 'https://genius.test/albums/40748'],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                        'song_art_image_url' => 'https://img.test/original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/203940',
                    ],
                    203941 => [
                        'id' => 203941,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 491687, 'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))', 'url' => 'https://genius.test/albums/491687'],
                        'release_date' => '2015-12-11',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 450],
                        'url' => 'https://genius.test/songs/203941',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    35659 => [
                        'id' => 35659,
                        'name' => 'Les leР“В§ons',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-01-31',
                        'cover_art_thumbnail_url' => 'https://img.test/lesson-cover.jpg',
                    ],
                    40748 => [
                        'id' => 40748,
                        'name' => 'РІв‚¬С™',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                        'cover_art_thumbnail_url' => 'https://img.test/original-cover.jpg',
                    ],
                    491687 => [
                        'id' => 491687,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-11',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    35659 => [139735 => 24],
                    40748 => [203940 => 8],
                    491687 => [203941 => 8],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Tous les mР“Р„mes',
                    durationSeconds: 216,
                    audioUrl: 'https://audio.test/tous-correct.mp3',
                    albumTitle: 'РІв‚¬С™',
                    trackNumber: 8,
                    artistNames: ['Stromae'],
                    releaseYear: 2013,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $existingTrack->refresh();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(0, $result['unmatched_tracks']);
        $this->assertSame($existingTrack->id, Track::query()->where('genius_id', 203940)->value('id'));
        $this->assertStringStartsWith('Tous les', (string) $existingTrack->title);
        $this->assertSame('https://audio.test/tous-correct.mp3', $existingTrack->audio_url);
        $this->assertSame(203940, $existingTrack->genius_id);
        $this->assertSame($originalAlbum->id, $existingTrack->album_id);
    }

    public function test_sync_artist_page_prefers_version_specific_track_when_parsed_title_includes_remix_marker(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 901,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                    [
                        'id' => 902,
                        'title' => 'Alors On Danse (90\'s Remix)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    901 => [
                        'id' => 901,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/alors-original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/901',
                    ],
                    902 => [
                        'id' => 902,
                        'title' => 'Alors On Danse (90\'s Remix)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/alors-remix.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 250],
                        'url' => 'https://genius.test/songs/902',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 903) {
                    return null;
                }

                return [
                    'id' => 903,
                    'name' => 'Cheese',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'release_date' => '2010-01-01',
                    'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [901 => 1, 902 => 2];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse (90\'s Remix)',
                    durationSeconds: 173,
                    audioUrl: 'https://audio.test/alors-remix.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(902, $track->genius_id);
    }

    public function test_sync_artist_page_attaches_unmatched_album_page_track_to_existing_local_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        $existingAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Р В РІР‚вЂќ4Р В РЎСџР В Р’В 4Р В РІР‚в„ўР В РЎв„ў4 Р В РЎв„ўР В Р’ВР В РІР‚Сњ (Р В РІР‚СњР В РІР‚СћР В РІР‚С”Р В Р’В®Р В РЎв„ўР В Р Р‹ Р В РЎСџ4Р В РЎв„ў)',
            'slug' => 'z4-deluxe-pack',
            'cover_image_url' => null,
            'release_date' => '2020-11-20',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 701,
                    'title' => 'Р РЋР В°Р С',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)'],
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 901,
                    'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    'url' => 'https://genius.test/albums/901',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-11-20',
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 701) {
                    return null;
                }

                return [
                    'id' => 701,
                    'title' => 'Р РЋР В°Р С',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'featured_artists' => [],
                    'album' => ['id' => 901, 'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)', 'url' => 'https://genius.test/albums/901'],
                    'release_date' => '2020-11-20',
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                    'song_art_image_url' => 'https://img.test/sam.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 100],
                    'url' => 'https://genius.test/songs/701',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 901) {
                    return null;
                }

                return [
                    'id' => 901,
                    'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-11-20',
                    'cover_art_thumbnail_url' => 'https://img.test/deluxe-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 901 ? [701 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР В°Р С',
                    durationSeconds: 170,
                    audioUrl: 'https://audio.test/sam.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
                new ParsedTrack(
                    title: 'Р вЂ”4N',
                    durationSeconds: 168,
                    audioUrl: 'https://audio.test/z4n.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 2,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $matchedTrack = Track::query()->where('genius_id', 701)->with('album')->firstOrFail();
        $fallbackTrack = Track::query()->where('audio_url', 'https://audio.test/z4n.mp3')->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertNotNull($matchedTrack->album);
        $this->assertSame($matchedTrack->album_id, $fallbackTrack->album_id);
        $this->assertSame($existingAlbum->id, $matchedTrack->album_id);
        $this->assertNotSame($existingAlbum->getOriginal('title'), $fallbackTrack->album?->title);
    }

    public function test_sync_artist_page_keeps_unmatched_album_page_track_as_single_when_no_local_album_exists(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р вЂ”4N',
                    durationSeconds: 168,
                    audioUrl: 'https://audio.test/z4n-single.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 2,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertTrue($result['genius_matched']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertNull($track->album_id);
        $this->assertNull($track->genius_id);
        $this->assertSame(0, Album::query()->count());
    }

    public function test_sync_artist_page_splits_fallback_artist_names_when_track_is_unmatched(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р С™Р С•Р Р…РЎвЂљРЎР‚Р С•Р В»РЎРЉ',
                    durationSeconds: 180,
                    audioUrl: 'https://audio.test/control.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Saluki and Anikv'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('artists')->firstOrFail();

        $this->assertFalse($result['genius_matched']);
        $this->assertTrue(Artist::query()->where('name', 'Saluki')->exists());
        $this->assertTrue(Artist::query()->where('name', 'Anikv')->exists());
        $this->assertFalse(Artist::query()->where('name', 'Saluki and Anikv')->exists());
        $this->assertEqualsCanonicalizing(['Saluki', 'Anikv'], $track->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_prefers_existing_original_album_over_later_live_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $originalAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Racine carree',
            'slug' => 'racine-carree',
            'cover_image_url' => null,
            'release_date' => '2013-08-16',
            'genius_id' => null,
        ]);

        $liveAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'РІв‚¬С™ (Racine carree (Live))',
            'slug' => 'racine-carree-live',
            'cover_image_url' => null,
            'release_date' => '2015-12-15',
            'genius_id' => 502,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 401,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)'],
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    ],
                    [
                        'id' => 402,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 15],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)', 'release_date' => '2013-08-16'],
                    ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))', 'release_date' => '2015-12-15'],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    401 => [
                        'id' => 401,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)', 'url' => 'https://genius.test/albums/501'],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                        'song_art_image_url' => 'https://img.test/papaoutai.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 1200],
                        'url' => 'https://genius.test/songs/401',
                    ],
                    402 => [
                        'id' => 402,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))', 'url' => 'https://genius.test/albums/502'],
                        'release_date' => '2015-12-15',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 15],
                        'song_art_image_url' => 'https://img.test/papaoutai-live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 1800],
                        'url' => 'https://genius.test/songs/402',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    501 => [
                        'id' => 501,
                        'name' => 'РІв‚¬С™ (Racine carree)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                    ],
                    502 => [
                        'id' => 502,
                        'name' => 'РІв‚¬С™ (Racine carree (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-15',
                    ],
                    default => null,
                };
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [401 => 4, 402 => 11];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Papaoutai',
                    durationSeconds: 233,
                    audioUrl: 'https://audio.test/papaoutai.mp3',
                    albumTitle: 'Racine carree',
                    trackNumber: 4,
                    artistNames: ['Stromae'],
                    releaseYear: 2013,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame('Papaoutai', $track->title);
        $this->assertSame(401, $track->genius_id);
        $this->assertStringContainsString('Racine carree', (string) ($track->album?->title ?? ''));
        $this->assertNotSame($liveAlbum->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_matches_cortisol_via_search_and_reuses_existing_local_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Saluki',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'CORTISOL',
            'slug' => 'cortisol',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [['id' => 611, 'name' => 'CORTISOL', 'release_date' => '2023-05-19']];
            }

            public function searchSongs(string $query): array
            {
                if (stripos($query, 'cortisol') === false) {
                    return [];
                }

                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 611, 'name' => 'CORTISOL'],
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 611, 'name' => 'CORTISOL', 'url' => 'https://genius.test/albums/611'],
                    'release_date' => '2023-05-19',
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 611) {
                    return null;
                }

                return [
                    'id' => 611,
                    'name' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-05-19',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 611 ? [601 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_reuses_existing_album_context_when_song_detail_points_to_a_different_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'SALUKI',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'CORTISOL',
            'slug' => 'cortisol',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);
        $album->artists()->sync([$artist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    ['id' => 920, 'name' => 'EUPHORIA', 'release_date' => '2026-03-27'],
                ];
            }

            public function searchSongs(string $query): array
            {
                if (stripos($query, 'cortisol') === false) {
                    return [];
                }

                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => null,
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 920, 'name' => 'EUPHORIA', 'url' => 'https://genius.test/albums/920'],
                    'release_date' => '2026-03-27',
                    'release_date_components' => ['year' => 2026, 'month' => 3, 'day' => 27],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 920) {
                    return null;
                }

                return [
                    'id' => 920,
                    'name' => 'EUPHORIA',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2026-03-27',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'SALUKI',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol-context.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['SALUKI'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
        $this->assertSame('CORTISOL', $track->album?->title);
    }

    public function test_sync_artist_page_creates_album_from_genius_when_local_album_is_missing(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [['id' => 611, 'name' => 'CORTISOL', 'release_date' => '2023-05-19']];
            }

            public function searchSongs(string $query): array
            {
                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 611, 'name' => 'CORTISOL'],
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 611, 'name' => 'CORTISOL', 'url' => 'https://genius.test/albums/611'],
                    'release_date' => '2023-05-19',
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 611) {
                    return null;
                }

                return [
                    'id' => 611,
                    'name' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-05-19',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 611 ? [601 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol-single.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->firstOrFail();
        $album = Album::query()->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame('CORTISOL', $album->title);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_creates_genius_album_for_matched_face_track_when_local_album_is_missing(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'FACE']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'FACE', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 501,
                    'title' => 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'album' => ['id' => 323781, 'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)'],
                    'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 323781,
                    'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'release_date' => '2015-10-30',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'title' => 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'featured_artists' => [],
                    'album' => ['id' => 323781, 'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)', 'url' => 'https://genius.test/albums/323781'],
                    'release_date' => '2015-10-30',
                    'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                    'song_art_image_url' => 'https://img.test/gosha.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/501',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 323781) {
                    return null;
                }

                return [
                    'id' => 323781,
                    'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'release_date' => '2015-10-30',
                    'cover_art_thumbnail_url' => 'https://img.test/cursed-seal.jpg',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 323781 ? [501 => 2] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'FACE',
            artistSlug: 'face',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    durationSeconds: 170,
                    audioUrl: 'https://audio.test/gosha.mp3',
                    albumTitle: null,
                    trackNumber: 2,
                    artistNames: ['FACE'],
                    releaseYear: 2015,
                    genres: [],
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album.artists')->firstOrFail();
        $album = Album::query()->with('artists')->firstOrFail();

        $this->assertSame(501, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(323781, $album->genius_id);
        $this->assertStringContainsString('CURSED SEAL', (string) $album->title);
        $this->assertSame(['FACE'], $album->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_keeps_muzofond_album_context_separate_from_different_genius_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'SALUKI',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $muzofondAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'SORRY I\'M WORKING ON THE WILD EA$T',
            'slug' => 'sorry-im-working',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);
        $muzofondAlbum->artists()->sync([$artist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 9013479,
                    'title' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 620708, 'name' => 'WILD EA$T'],
                    'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 21],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 620708,
                    'name' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-04-21',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 9013479) {
                    return null;
                }

                return [
                    'id' => 9013479,
                    'title' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 620708, 'name' => 'WILD EA$T', 'url' => 'https://genius.test/albums/620708'],
                    'release_date' => '2023-04-21',
                    'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 21],
                    'song_art_image_url' => 'https://img.test/wild-east.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/9013479',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 620708) {
                    return null;
                }

                return [
                    'id' => 620708,
                    'name' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-04-21',
                    'cover_art_thumbnail_url' => 'https://img.test/wild-east-cover.jpg',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 620708 ? [9013479 => 2] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'WILD EA$T',
                    durationSeconds: 112,
                    audioUrl: 'https://audio.test/wild-east.mp3',
                    albumTitle: 'SORRY I\'M WORKING ON THE WILD EA$T',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();
        $freshMuzofondAlbum = Album::query()->find($muzofondAlbum->id);
        $geniusAlbum = Album::query()->where('genius_id', 620708)->firstOrFail();

        $this->assertSame(9013479, $track->genius_id);
        $this->assertSame($geniusAlbum->id, $track->album_id);
        $this->assertSame('WILD EA$T', $geniusAlbum->title);
        $this->assertNull($freshMuzofondAlbum);
    }
}
