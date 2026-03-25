<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Artist> */
class ArtistFactory extends Factory
{
    protected $model = Artist::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'image_url' => fake()->imageUrl(800, 800, 'music', true),
        ];
    }
}
