<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Track> */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'album_id' => Album::factory(),
            'title' => fake()->sentence(3),
            'duration_seconds' => fake()->numberBetween(120, 320),
            'audio_url' => fake()->url(),
            'cover_image_url' => fake()->imageUrl(800, 800, 'music', true),
            'track_number' => fake()->numberBetween(1, 12),
        ];
    }
}
