<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\Genius\GeniusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RepairTrackGeniusMatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_repairs_missing_and_wrong_genius_matches_in_place(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Король и Шут',
            'slug' => 'korol-i-sut',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        $soundtrackAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Король и Шут (Официальный саундтрек), Часть 1',
            'slug' => 'korol-i-shut-ost-part-1',
            'cover_image_url' => null,
            'release_date' => '2023-04-06',
            'genius_id' => 7102,
        ]);
        $unmatchedTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => null,
            'title' => 'Ели мясо мужики',
            'duration_seconds' => 155,
            'audio_url' => 'https://audio.test/eli.mp3',
            'original_link' => 'https://audio.test/eli.mp3',
            'release_year' => null,
            'genres' => [],
            'track_number' => 1,
            'genius_id' => null,
            'genius_url' => null,
        ]);
        $wrongMatchedTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $soundtrackAlbum->id,
            'title' => 'Лесник (Из сериала «Король и Шут»)',
            'duration_seconds' => 193,
            'audio_url' => 'https://audio.test/lesnik.mp3',
            'original_link' => 'https://audio.test/lesnik.mp3',
            'release_year' => null,
            'genres' => [],
            'track_number' => 22,
            'genius_id' => 8997469,
            'genius_url' => 'https://genius.test/songs/8997469',
        ]);

        $this->app->instance(GeniusClient::class, new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [[
                    'id' => 1381291,
                    'name' => 'Король и Шут (Korol I Shut)',
                    'alternate_names' => [],
                ]];
            }

            public function artist(int $geniusId): ?array
            {
                return [
                    'id' => 1381291,
                    'name' => 'Король и Шут (Korol I Shut)',
                    'alternate_names' => [],
                ];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 9014645,
                        'title' => 'Ели мясо мужики (The men ate the meat) (Live)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                    [
                        'id' => 4621957,
                        'title' => 'Лесник (Forester)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                    [
                        'id' => 8997469,
                        'title' => 'Лесник (Из сериала «Король и Шут») (Forester OST)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function songDetails(array $songIds): array
            {
                $details = [];
                foreach ($songIds as $songId) {
                    $details[$songId] = $this->song((int) $songId);
                }

                return $details;
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [
                    [
                        'id' => 4139100,
                        'title' => 'Ели мясо мужики (Men Were Eating Meat)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                    [
                        'id' => 9014645,
                        'title' => 'Ели мясо мужики (The men ate the meat) (Live)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                    [
                        'id' => 4621957,
                        'title' => 'Лесник (Forester)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                    [
                        'id' => 8997469,
                        'title' => 'Лесник (Из сериала «Король и Шут») (Forester OST)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => null,
                    ],
                ];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    4139100 => [
                        'id' => 4139100,
                        'title' => 'Ели мясо мужики (Men Were Eating Meat)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => ['id' => 7101, 'name' => 'Мёртвый анархист (Dead anarchist)'],
                        'release_date' => '1997-12-18',
                        'release_date_components' => ['year' => 1997, 'month' => 12, 'day' => 18],
                        'song_art_image_url' => 'https://img.test/eli.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/4139100',
                    ],
                    4621957 => [
                        'id' => 4621957,
                        'title' => 'Лесник (Forester)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => ['id' => 7101, 'name' => 'Мёртвый анархист (Dead anarchist)'],
                        'release_date' => '1997-12-18',
                        'release_date_components' => ['year' => 1997, 'month' => 12, 'day' => 18],
                        'song_art_image_url' => 'https://img.test/lesnik.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 450],
                        'url' => 'https://genius.test/songs/4621957',
                    ],
                    8997469 => [
                        'id' => 8997469,
                        'title' => 'Лесник (Из сериала «Король и Шут») (Forester OST)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => ['id' => 7102, 'name' => 'Король и Шут (Официальный саундтрек), Часть 1'],
                        'release_date' => '2023-04-06',
                        'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 6],
                        'song_art_image_url' => 'https://img.test/lesnik-ost.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 320],
                        'url' => 'https://genius.test/songs/8997469',
                    ],
                    9014645 => [
                        'id' => 9014645,
                        'title' => 'Ели мясо мужики (The men ate the meat) (Live)',
                        'artist_names' => 'Король и Шут (Korol I Shut)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'featured_artists' => [],
                        'album' => ['id' => 7103, 'name' => 'Live in Kiev'],
                        'release_date' => null,
                        'release_date_components' => [],
                        'song_art_image_url' => 'https://img.test/eli-live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 120],
                        'url' => 'https://genius.test/songs/9014645',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    7101 => [
                        'id' => 7101,
                        'name' => 'Мёртвый анархист (Dead anarchist)',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'release_date' => '1997-12-18',
                        'cover_art_thumbnail_url' => 'https://img.test/dead-anarchist.jpg',
                    ],
                    7102 => [
                        'id' => 7102,
                        'name' => 'Король и Шут (Официальный саундтрек), Часть 1',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'release_date' => '2023-04-06',
                        'cover_art_thumbnail_url' => 'https://img.test/ost.jpg',
                    ],
                    7103 => [
                        'id' => 7103,
                        'name' => 'Live in Kiev',
                        'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут (Korol I Shut)']],
                        'release_date' => null,
                        'cover_art_thumbnail_url' => 'https://img.test/live.jpg',
                    ],
                    default => null,
                };
            }
        });

        $exitCode = Artisan::call('tracks:repair-genius-matches', [
            '--track-id' => [$unmatchedTrack->id, $wrongMatchedTrack->id],
            '--ignore-album-context' => true,
        ]);

        $unmatchedTrack->refresh();
        $wrongMatchedTrack->refresh();


        $this->assertSame(0, $exitCode);
        $this->assertSame(4139100, $unmatchedTrack->genius_id);
        $this->assertSame('Ели мясо мужики', $unmatchedTrack->title);
        $this->assertSame('Мёртвый анархист', $unmatchedTrack->album?->title);
        $this->assertSame(4621957, $wrongMatchedTrack->genius_id);
        $this->assertSame('Лесник', $wrongMatchedTrack->title);
        $this->assertSame('Мёртвый анархист', $wrongMatchedTrack->album?->title);
    }
}
