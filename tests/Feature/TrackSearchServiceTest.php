<?php

namespace Tests\Feature;

use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_keeps_an_unlinked_audio_copy_but_does_not_label_it_with_a_stale_album_id(): void
    {
        $artist = Artist::query()->create(['name' => 'Stromae', 'slug' => 'stromae']);
        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => '√ (Racine carrée)',
            'slug' => 'racine-carree',
        ]);
        $canonical = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Tous les mêmes',
            'duration_seconds' => 214,
            'audio_url' => 'https://audio.test/4885356.mp3',
            'muzofond_audio_id' => '4885356',
            'genius_id' => 203940,
            'genius_url' => 'https://genius.com/Stromae-tous-les-memes-lyrics',
        ]);
        $canonical->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 5]);
        $alternate = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Tous les mêmes',
            'duration_seconds' => 213,
            'audio_url' => 'https://audio.test/3565063.mp3',
            'muzofond_audio_id' => '3565063',
            'genius_id' => null,
            'genius_url' => 'https://genius.com/Stromae-tous-les-memes-lyrics',
        ]);

        $results = app(TrackSearchService::class)->searchTracksPaginated(null)->getCollection();

        $this->assertEqualsCanonicalizing([$canonical->id, $alternate->id], $results->pluck('id')->all());

        $resource = TrackResource::make($alternate->load(['artist', 'artists', 'album', 'albums']))->resolve();
        $this->assertNull($resource['album']);
    }
}
