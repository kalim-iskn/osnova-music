<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlbumTrackPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_page_uses_its_own_cover_and_pivot_track_numbers(): void
    {
        $artist = Artist::query()->create(['name' => 'Context Artist', 'slug' => 'context-artist']);
        $primaryAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Primary Release',
            'slug' => 'primary-release',
            'cover_image_url' => 'https://images.test/primary.jpg',
        ]);
        $shownAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Shown Release',
            'slug' => 'shown-release',
            'cover_image_url' => 'https://images.test/shown.jpg',
        ]);

        $secondTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $primaryAlbum->id,
            'title' => 'Second by ID, first by release order',
            'duration_seconds' => 180,
            'audio_url' => 'https://audio.test/second.mp3',
        ]);
        $firstTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $primaryAlbum->id,
            'title' => 'First by release order',
            'duration_seconds' => 181,
            'audio_url' => 'https://audio.test/first.mp3',
        ]);

        $secondTrack->albums()->attach($shownAlbum->id, ['is_primary' => false, 'track_number' => 2]);
        $firstTrack->albums()->attach($shownAlbum->id, ['is_primary' => false, 'track_number' => 1]);
        $secondTrack->albums()->attach($primaryAlbum->id, ['is_primary' => true, 'track_number' => 9]);
        $firstTrack->albums()->attach($primaryAlbum->id, ['is_primary' => true, 'track_number' => 10]);

        $response = $this->get(route('albums.show', $shownAlbum->slug));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Albums/Show')
            ->where('showTrackNumbers', true)
            ->where('tracks.data.0.id', $firstTrack->id)
            ->where('tracks.data.0.track_number', 1)
            ->where('tracks.data.0.album.title', $shownAlbum->title)
            ->where('tracks.data.0.cover_image_url', $shownAlbum->cover_image_url)
            ->where('tracks.data.1.id', $secondTrack->id)
            ->where('tracks.data.1.track_number', 2)
            ->where('tracks.data.1.album.title', $shownAlbum->title)
            ->where('tracks.data.1.cover_image_url', $shownAlbum->cover_image_url));

        $shownAlbum->tracks()->updateExistingPivot($secondTrack->id, ['track_number' => null]);

        $this->get(route('albums.show', $shownAlbum->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Albums/Show')
                ->where('showTrackNumbers', false));

        $wasStrict = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            $this->get(route('tracks.show', $secondTrack->id))->assertOk();
        } finally {
            Model::preventAccessingMissingAttributes($wasStrict);
        }
    }
}
