<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Album> */
class AlbumFactory extends Factory
{
    protected $model = Album::class;

    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'artist_id' => Artist::factory(),
            'title' => $title,
            'slug' => Str::slug($title.'-'.fake()->unique()->numberBetween(100, 999)),
            'cover_image_url' => fake()->imageUrl(800, 800, 'album', true),
            'release_date' => fake()->date(),
        ];
    }
}
