<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Models\User;
use App\Services\TrackSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MusicCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $slug): Artist
    {
        return Artist::query()->create(['name' => $slug, 'slug' => $slug]);
    }

    private function track(Artist $artist, int $number): Track
    {
        return Track::query()->create(['artist_id' => $artist->id, 'title' => "Track {$number}", 'audio_url' => "https://audio.test/{$number}.mp3", 'duration_seconds' => 120]);
    }

    public function test_release_likes_are_idempotent_private_and_do_not_like_tracks(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $artist = $this->artist('artist');
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Release', 'slug' => 'release']);
        $this->postJson(route('albums.like', $album))->assertUnauthorized();
        $this->actingAs($owner)->postJson(route('albums.like', $album))->assertOk()->assertJson(['liked' => true, 'liked_album_ids' => [$album->id]]);
        $this->postJson(route('albums.like', $album))->assertOk();
        $this->assertDatabaseCount('album_user_likes', 1);
        $this->assertDatabaseCount('track_user_likes', 0);
        $this->actingAs($other)->get('/library/tracks?tab=releases')->assertInertia(fn (Assert $page) => $page->has('releases.data', 0));
        $this->actingAs($owner)->get('/library/tracks?tab=releases')->assertInertia(fn (Assert $page) => $page->has('releases.data', 1)->where('releases.data.0.id', $album->id));
        $this->deleteJson(route('albums.unlike', $album))->assertOk()->assertJson(['liked' => false, 'liked_album_ids' => []]);
        $this->assertDatabaseCount('album_user_likes', 0);
    }

    public function test_artist_filter_and_queue_include_features_across_all_pages_only_from_own_likes(): void
    {
        $owner = User::factory()->create();
        $primary = $this->artist('primary');
        $featured = $this->artist('featured');
        $unrelated = $this->artist('unrelated');
        $ids = [];
        for ($i = 0; $i < 22; $i++) {
            $track = $this->track($primary, $i);
            $track->artists()->attach($featured->id, ['credit_role' => 'featured']);
            $ids[] = $track->id;
        }
        $excluded = $this->track($featured, 23);
        $other = $this->track($unrelated, 24);
        $owner->likedTracks()->attach([...$ids, $other->id]);
        $this->actingAs($owner)->get('/library/tracks?artist='.$featured->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tracks.meta.total', 22)->has('tracks.data', 20)->where('likedTracksTotal', 23)
            ->where('artistId', $featured->id)->has('artists', 3));
        $queue = $this->getJson('/library/tracks/queue?artist='.$featured->id)->assertOk()->json('tracks');
        $this->assertEqualsCanonicalizing($ids, array_column($queue, 'id'));
        $this->assertNotContains($excluded->id, array_column($queue, 'id'));
        $this->getJson('/library/tracks/queue?artist=invalid')->assertUnprocessable();
    }

    public function test_catalog_counts_include_primary_and_featured_credits_once(): void
    {
        $primary = $this->artist('primary');
        $featured = $this->artist('featured');
        $first = $this->track($primary, 1);
        $first->artists()->attach([$primary->id => ['credit_role' => 'primary'], $featured->id => ['credit_role' => 'featured']]);
        $this->track($primary, 2);
        $counts = app(TrackSearchService::class)->searchArtists(null)->pluck('tracks_count', 'id');
        $this->assertEquals(2, $counts[$primary->id]);
        $this->assertEquals(1, $counts[$featured->id]);
        $this->get('/artists/'.$primary->slug)->assertInertia(fn (Assert $page) => $page->where('artist.tracks_count', 2)->where('tracks.meta.total', 2));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('spotlightArtists.0.tracks_count', 1));
    }

    public function test_saved_releases_can_be_filtered_by_a_collaborating_artist_without_saved_tracks(): void
    {
        $owner = User::factory()->create();
        $primary = $this->artist('primary');
        $collaborator = $this->artist('collaborator');
        $album = Album::query()->create(['artist_id' => $primary->id, 'title' => 'Together', 'slug' => 'together']);
        $other = Album::query()->create(['artist_id' => $primary->id, 'title' => 'Solo', 'slug' => 'solo']);
        $album->artists()->attach($collaborator->id);
        $owner->likedAlbums()->attach([$album->id, $other->id]);
        $this->actingAs($owner)->get('/library/tracks?tab=releases&artist='.$collaborator->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('artists', 2)->has('tracks.data', 0)->has('releases.data', 1)
            ->where('releases.data.0.id', $album->id)->where('releases.meta.total', 1)->where('likedReleasesTotal', 2));
    }
}
