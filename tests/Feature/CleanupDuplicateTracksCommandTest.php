<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupDuplicateTracksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_duplicate_tracks_deletes_single_duplicate_and_keeps_genius_linked_track(): void
    {
        Storage::fake('public');

        $artist = Artist::query()->create([
            'name' => 'MAYOT',
            'slug' => 'mayot',
        ]);

        Storage::disk('public')->put('tracks/keep.mp3', 'same-bytes');
        Storage::disk('public')->put('tracks/delete.mp3', 'same-bytes');

        $keptTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => null,
            'title' => 'Снег',
            'duration_seconds' => 180,
            'audio_url' => '/storage/tracks/keep.mp3',
            'is_downloaded' => true,
            'genius_id' => 5070373,
        ]);
        $keptTrack->artists()->sync([$artist->id]);

        $duplicateTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => null,
            'title' => 'Sneg',
            'duration_seconds' => 180,
            'audio_url' => '/storage/tracks/delete.mp3',
            'is_downloaded' => true,
        ]);
        $duplicateTrack->artists()->sync([$artist->id]);

        $this->artisan('tracks:cleanup-duplicates')
            ->expectsOutputToContain('[deleted] Sneg | /storage/tracks/delete.mp3')
            ->assertExitCode(0);

        $this->assertDatabaseHas('tracks', [
            'id' => $keptTrack->id,
        ]);
        $this->assertDatabaseMissing('tracks', [
            'id' => $duplicateTrack->id,
        ]);
    }

    public function test_cleanup_duplicate_tracks_keeps_one_plain_single_when_only_unlinked_duplicates_exist(): void
    {
        Storage::fake('public');

        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
        ]);

        Storage::disk('public')->put('tracks/one.mp3', 'duplicate-bytes');
        Storage::disk('public')->put('tracks/two.mp3', 'duplicate-bytes');

        $firstTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => null,
            'title' => 'Alors on danse',
            'duration_seconds' => 207,
            'audio_url' => '/storage/tracks/one.mp3',
            'is_downloaded' => true,
        ]);
        $firstTrack->artists()->sync([$artist->id]);

        $secondTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => null,
            'title' => 'Alors   On   Danse',
            'duration_seconds' => 207,
            'audio_url' => '/storage/tracks/two.mp3',
            'is_downloaded' => true,
        ]);
        $secondTrack->artists()->sync([$artist->id]);

        $this->artisan('tracks:cleanup-duplicates')
            ->expectsOutputToContain('[deleted] Alors   On   Danse | /storage/tracks/two.mp3')
            ->assertExitCode(0);

        $this->assertDatabaseHas('tracks', [
            'id' => $firstTrack->id,
        ]);
        $this->assertDatabaseMissing('tracks', [
            'id' => $secondTrack->id,
        ]);
    }
}
