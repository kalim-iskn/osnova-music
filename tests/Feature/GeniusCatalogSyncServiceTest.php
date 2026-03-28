<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Track;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\Genius\GeniusClient;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeniusCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_artist_page_falls_back_to_search_and_prefers_oldest_non_demo_album(): void
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
                if (! str_contains($query, 'Снег')) {
                    return [];
                }

                return [
                    [
                        'id' => 102,
                        'title' => 'Снег',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Снег Demo'],
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 101,
                        'title' => 'Снег',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Original Winter'],
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    ],
                    [
                        'id' => 103,
                        'title' => 'Снег',
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
                        'title' => 'Снег',
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
                        'title' => 'Снег',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 202, 'name' => 'Снег Demo', 'url' => 'https://genius.test/albums/202'],
                        'release_date' => '2019-12-01',
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/demo.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/102',
                    ],
                    103 => [
                        'id' => 103,
                        'title' => 'Снег',
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
                        'name' => 'Снег Demo',
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
                    title: 'Снег',
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
        $this->assertSame('Снег', $track->title);
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
                    'title' => 'Снег',
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
                    'title' => 'Снег',
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
                    title: 'Снег',
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
                        'album' => ['name' => '√ (Racine carrée (Live))'],
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
                        'album' => ['id' => 801, 'name' => '√ (Racine carrée (Live))', 'url' => 'https://genius.test/albums/801'],
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
                        'name' => '√ (Racine carrée (Live))',
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
                        'album' => ['name' => 'в€љ (Racine carrГ©e (Live))'],
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 901,
                        'name' => 'в€љ (Racine carrГ©e (Live))',
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
                        'album' => ['id' => 901, 'name' => 'в€љ (Racine carrГ©e (Live))', 'url' => 'https://genius.test/albums/901'],
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
                        'name' => 'в€љ (Racine carrГ©e (Live))',
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
                    title: 'Контроль',
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
}
