<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AuditAudioVersionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_and_repair_keep_every_source_audio_file(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Example Album', 'slug' => 'example-album']);

        foreach ([
            ['id' => '1', 'title' => 'Example Song', 'duration' => 206],
            ['id' => '2', 'title' => 'Example Song', 'duration' => 211],
            ['id' => '3', 'title' => 'Example Song', 'duration' => 172],
            ['id' => '4', 'title' => 'Example Song (90s Remix)', 'duration' => 173],
        ] as $row) {
            $track = Track::query()->create([
                'artist_id' => $artist->id,
                'album_id' => $album->id,
                'title' => $row['title'],
                'muzofond_title' => $row['title'],
                'muzofond_album_title' => $album->title,
                'duration_seconds' => $row['duration'],
                'audio_url' => 'https://audio.test/' . $row['id'] . '.mp3',
                'muzofond_audio_id' => $row['id'],
                'genius_id' => $row['id'] === '3' ? 701 : null,
            ]);
            $track->albums()->sync([$album->id => ['is_primary' => true]]);
        }

        Artisan::call('tracks:audit-audio-versions', ['--artist-id' => [$artist->id]]);
        $wrong = Track::query()->where('muzofond_audio_id', '3')->firstOrFail();
        $this->assertSame(701, $wrong->genius_id);

        Artisan::call('tracks:audit-audio-versions', ['--artist-id' => [$artist->id], '--apply' => true]);
        $wrong->refresh();

        $this->assertSame(4, Track::query()->count());
        $this->assertSame('3', $wrong->muzofond_audio_id);
        $this->assertSame('https://audio.test/3.mp3', $wrong->audio_url);
        $this->assertNull($wrong->genius_id);
        $this->assertNull($wrong->album_id);
        $this->assertTrue($wrong->audio_version_ambiguous);
        $this->assertSame(0, $wrong->albums()->count());
    }
}
