<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (Artist::query()->exists()) {
            return;
        }

        $catalog = [
            [
                'artist' => ['name' => 'Neon Harbor', 'image_url' => 'https://picsum.photos/seed/neon-harbor/900/900'],
                'album' => ['title' => 'City Lights', 'cover_image_url' => 'https://picsum.photos/seed/city-lights/900/900', 'release_date' => '2025-10-10'],
                'tracks' => [
                    ['title' => 'Midnight Drive', 'duration_seconds' => 214, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3', 'track_number' => 1],
                    ['title' => 'Electric Blue', 'duration_seconds' => 196, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3', 'track_number' => 2],
                    ['title' => 'Ocean Avenue', 'duration_seconds' => 228, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-3.mp3', 'track_number' => 3],
                ],
            ],
            [
                'artist' => ['name' => 'Aurora Static', 'image_url' => 'https://picsum.photos/seed/aurora-static/900/900'],
                'album' => ['title' => 'Northern Signals', 'cover_image_url' => 'https://picsum.photos/seed/northern-signals/900/900', 'release_date' => '2025-11-02'],
                'tracks' => [
                    ['title' => 'Polar Echo', 'duration_seconds' => 205, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-4.mp3', 'track_number' => 1],
                    ['title' => 'Crystal Receiver', 'duration_seconds' => 232, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-5.mp3', 'track_number' => 2],
                    ['title' => 'Skyline Frequency', 'duration_seconds' => 187, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-6.mp3', 'track_number' => 3],
                ],
            ],
            [
                'artist' => ['name' => 'Velvet Atlas', 'image_url' => 'https://picsum.photos/seed/velvet-atlas/900/900'],
                'album' => ['title' => 'Fading Polaroids', 'cover_image_url' => 'https://picsum.photos/seed/fading-polaroids/900/900', 'release_date' => '2025-08-18'],
                'tracks' => [
                    ['title' => 'Paper Planes', 'duration_seconds' => 201, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-7.mp3', 'track_number' => 1],
                    ['title' => 'Afterglow Motel', 'duration_seconds' => 244, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-8.mp3', 'track_number' => 2],
                    ['title' => 'Static Postcards', 'duration_seconds' => 176, 'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-9.mp3', 'track_number' => 3],
                ],
            ],
        ];

        foreach ($catalog as $entry) {
            $artist = Artist::create([
                'name' => $entry['artist']['name'],
                'slug' => Str::slug($entry['artist']['name']),
                'image_url' => $entry['artist']['image_url'],
            ]);

            $album = Album::create([
                'artist_id' => $artist->id,
                'title' => $entry['album']['title'],
                'slug' => Str::slug($entry['artist']['name'].' '.$entry['album']['title']),
                'cover_image_url' => $entry['album']['cover_image_url'],
                'release_date' => $entry['album']['release_date'],
            ]);

            foreach ($entry['tracks'] as $trackData) {
                Track::create([
                    'artist_id' => $artist->id,
                    'album_id' => $album->id,
                    'title' => $trackData['title'],
                    'duration_seconds' => $trackData['duration_seconds'],
                    'audio_url' => $trackData['audio_url'],
                    'cover_image_url' => $entry['album']['cover_image_url'],
                    'track_number' => $trackData['track_number'],
                ]);
            }

            Track::create([
                'artist_id' => $artist->id,
                'album_id' => null,
                'title' => $entry['artist']['name'].' — Live Session',
                'duration_seconds' => 154,
                'audio_url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-10.mp3',
                'cover_image_url' => $entry['artist']['image_url'],
            ]);
        }
    }
}
