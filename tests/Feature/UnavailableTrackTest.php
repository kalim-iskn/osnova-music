<?php

namespace Tests\Feature;

use App\Http\Resources\TrackResource;
use App\Models\Artist;
use App\Models\Track;
use App\Models\Album;
use App\Jobs\FindMissingTrackAudioJob;
use App\Services\Genius\AlbumTracklistResult;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\Genius\GeniusClient;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class UnavailableTrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_counts_missing_audio_jobs_separately_from_untracked_parsing(): void
    {
        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'tracks-parsing', 'payload' => json_encode(['displayName' => FindMissingTrackAudioJob::class]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        $this->getJson(route('parsing.status'))->assertOk()
            ->assertJsonPath('queue.pending', 1)
            ->assertJsonPath('queue.audio_search_pending', 1)
            ->assertJsonPath('queue.untracked_pending', 0);
    }

    public function test_sync_uses_complete_source_album_for_remix_and_missing_song(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist', 'genius_id' => 91]);
        $album = ['id' => 123, 'name' => 'Release', 'artist' => ['id' => 91, 'name' => 'Artist']];
        $remix = ['id' => 456, 'title' => 'Show (Rock Remix)', 'primary_artist' => ['id' => 91, 'name' => 'Artist'], 'album' => $album];
        $missing = ['id' => 457, 'title' => 'Missing', 'primary_artist' => ['id' => 91, 'name' => 'Artist'], 'album' => $album];
        $original = ['id' => 455, 'title' => 'Show', 'primary_artist' => ['id' => 91, 'name' => 'Artist'], 'album' => ['id' => 200, 'name' => 'Another Release']];
        $client = new class($album, $remix, $missing, $original) extends GeniusClient {
            public function __construct(private array $release, private array $remix, private array $missing, private array $original) {}
            public function artist(int $id): ?array { return ['id' => $id, 'name' => 'Artist']; }
            public function allArtistSongs(int $id): array { return [$this->original, $this->remix, $this->missing]; }
            public function allArtistAlbums(int $id): array { return [$this->release]; }
            public function artistAlbumCatalogIsComplete(): bool { return true; }
            public function album(int $id): ?array { return $this->release; }
            public function searchSongs(string $query): array { return []; }
            public function searchAlbums(string $query): ?array { return []; }
            public function albumTracksMany(array $ids): array { return [123 => $this->rows()]; }
            public function albumTracklistResult(int $id): AlbumTracklistResult { return new AlbumTracklistResult($this->rows(), true); }
            public function song(int $id): ?array { return match ($id) { 455 => $this->original, 456 => $this->remix, 457 => $this->missing, default => null }; }
            public function songDetails(array $ids): array { return []; }
            public function songAppleMusicDurationSeconds(int $id): ?float { return null; }
            private function rows(): array { return [['number' => 1, 'song' => $this->remix], ['number' => 2, 'song' => $this->missing]]; }
        };
        $source = new ParsedTrack('Show', 98, 'https://audio.test/1.mp3', 'Release', 1, ['Artist'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE, audioFileId: '1');
        $page = new ParsedArtistPage('Artist', 'artist', null, [$source], [
            ['title' => 'Release', 'url' => 'https://source.test/release', 'complete' => true, 'tracks' => [$source]],
        ]);
        (new GeniusCatalogSyncService($client))->syncArtistPage($page);
        $played = Track::query()->where('muzofond_audio_id', '1')->firstOrFail();
        $this->assertSame(456, $played->genius_id);
        $this->assertSame(1, $played->albums()->where('albums.genius_id', 123)->firstOrFail()->pivot->track_number);
        $placeholder = Track::query()->where('genius_id', 457)->firstOrFail();
        $this->assertNull($placeholder->audio_url);
        $this->assertSame(2, $placeholder->albums()->where('albums.genius_id', 123)->firstOrFail()->pivot->track_number);
        Queue::assertPushedOn('tracks-parsing', FindMissingTrackAudioJob::class);
    }

    public function test_sync_matches_symbolic_song_in_its_confirmed_symbolic_album(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Artist::query()->create(['name' => 'Artist', 'slug' => 'artist', 'genius_id' => 91]);
        $album = ['id' => 123, 'name' => '? (Question Mark)', 'artist' => ['id' => 91, 'name' => 'Artist']];
        $song = ['id' => 456, 'title' => '$$$', 'primary_artist' => ['id' => 91, 'name' => 'Artist'], 'album' => $album];
        $client = new class($album, $song) extends GeniusClient {
            public function __construct(private array $release, private array $songPayload) {}
            public function artist(int $id): ?array { return ['id' => $id, 'name' => 'Artist']; }
            public function allArtistSongs(int $id): array { return [$this->songPayload]; }
            public function allArtistAlbums(int $id): array { return [$this->release]; }
            public function artistAlbumCatalogIsComplete(): bool { return true; }
            public function album(int $id): ?array { return $this->release; }
            public function searchSongs(string $query): array { return []; }
            public function searchAlbums(string $query): ?array { return []; }
            public function albumTracksMany(array $ids): array { return [123 => $this->rows()]; }
            public function albumTracklistResult(int $id): AlbumTracklistResult { return new AlbumTracklistResult($this->rows(), true); }
            public function song(int $id): ?array { return $this->songPayload; }
            public function songDetails(array $ids): array { return []; }
            public function songAppleMusicDurationSeconds(int $id): ?float { return null; }
            private function rows(): array { return [['number' => 11, 'song' => $this->songPayload]]; }
        };
        $source = new ParsedTrack('Sss', 130, 'https://audio.test/1.mp3', '?', 11, ['Artist'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE, audioFileId: '1',
            albumAppearances: [['title' => '?', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 11]]);
        $page = new ParsedArtistPage('Artist', 'artist', null, [$source], [
            ['title' => '?', 'url' => 'https://source.test/question', 'complete' => true, 'tracks' => [$source]],
        ]);
        (new GeniusCatalogSyncService($client))->syncArtistPage($page);
        $track = Track::query()->where('muzofond_audio_id', '1')->firstOrFail();
        $this->assertSame(456, $track->genius_id);
        $this->assertSame(11, $track->albums()->where('albums.genius_id', 123)->firstOrFail()->pivot->track_number);
        $this->assertSame(1, Track::query()->count());
    }

    public function test_unavailable_track_has_no_playback_url_and_cannot_be_streamed_or_counted(): void
    {
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist']);
        $track = Track::query()->create(['artist_id' => $artist->id, 'title' => 'Song',
            'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 42]);
        $track->load(['artist', 'artists', 'album', 'albums']);
        $payload = TrackResource::make($track)->resolve();
        $this->assertFalse($payload['is_playable']);
        $this->assertNull($payload['playback_url']);
        $this->assertNull($payload['duration_seconds']);
        $this->assertNull($payload['duration_human']);
        $this->get(route('tracks.stream', $track))->assertNotFound();
        $this->post(route('tracks.play', $track))->assertNotFound();
    }

    public function test_missing_song_is_created_once_only_from_a_complete_confirmed_album(): void
    {
        Queue::fake();
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist', 'genius_id' => 91]);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Release', 'slug' => 'release', 'genius_id' => 123]);
        $song = ['id' => 456, 'title' => 'Missing', 'track_number' => 2,
            'primary_artist' => ['id' => 91, 'name' => 'Artist'], 'featured_artists' => [['id' => 92, 'name' => 'Guf']],
            'release_date_components' => ['year' => 2020], 'stats' => ['pageviews' => 1000],
            'album' => ['id' => 123, 'name' => 'Release']];
        $source = new ParsedTrack('Other', 100, 'https://audio.test/1.mp3', 'Release', 1, ['Artist'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE, audioFileId: '1');
        $page = new ParsedArtistPage('Artist', 'artist', null, [$source], [['title' => 'Release', 'complete' => true, 'tracks' => [$source]]]);
        $client = new class($song) extends GeniusClient {
            public function __construct(private array $payload) {}
            public function song(int $id): ?array { return $this->payload; }
            public function artist(int $id): ?array { return ['id' => $id, 'name' => $id === 92 ? 'Guf' : 'Artist']; }
        };
        $service = new GeniusCatalogSyncService($client);
        $r = new ReflectionClass($service);
        $r->getProperty('albumTracklistResultCache')->setValue($service, [123 => new AlbumTracklistResult([['number' => 2, 'song' => $song]], true)]);
        $r->getProperty('albumTrackSongCache')->setValue($service, [123 => [$song]]);
        $create = $r->getMethod('createMissingAlbumTracks');
        $create->invoke($service, $artist, $page);
        $track = Track::query()->where('genius_id', 456)->firstOrFail();
        $this->assertNull($track->audio_url);
        $this->assertNull($track->duration_seconds);
        $this->assertSame(2, $track->albums()->whereKey($album->id)->firstOrFail()->pivot->track_number);
        $this->assertSame(2020, $track->release_year);
        $this->assertSame(50, $track->genius_pageviews);
        $this->assertSame('featured', $track->artists()->where('artists.genius_id', 92)->firstOrFail()->pivot->credit_role);
        $create->invoke($service, $artist, $page);
        $this->assertSame(1, Track::query()->where('genius_id', 456)->count());
        Queue::assertPushed(FindMissingTrackAudioJob::class, 1);
        Queue::assertPushedOn('tracks-parsing', FindMissingTrackAudioJob::class);
    }

    public function test_found_but_unmatched_source_and_incomplete_album_never_create_placeholder(): void
    {
        Queue::fake();
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist', 'genius_id' => 91]);
        Album::query()->create(['artist_id' => $artist->id, 'title' => 'Release', 'slug' => 'release', 'genius_id' => 123]);
        $song = ['id' => 456, 'title' => 'Intro', 'primary_artist' => ['id' => 91, 'name' => 'Artist']];
        $source = new ParsedTrack('Intro', 32, 'https://audio.test/1.mp3', 'Release', 1, ['Artist'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE, audioFileId: '1');
        $page = new ParsedArtistPage('Artist', 'artist', null, [$source], [['title' => 'Release', 'complete' => true, 'tracks' => [$source]]]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $r = new ReflectionClass($service);
        $r->getProperty('albumTracklistResultCache')->setValue($service, [123 => new AlbumTracklistResult([['song' => $song]], true)]);
        $r->getProperty('albumTrackSongCache')->setValue($service, [123 => [$song]]);
        $r->getMethod('createMissingAlbumTracks')->invoke($service, $artist, $page);
        $page->tracks = [];
        $r->getProperty('albumTracklistResultCache')->setValue($service, [123 => new AlbumTracklistResult([['song' => $song]], false)]);
        $r->getMethod('createMissingAlbumTracks')->invoke($service, $artist, $page);
        $this->assertSame(0, Track::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_deferred_bvf_introduction_with_a_found_source_is_not_replaced_by_a_placeholder(): void
    {
        Queue::fake();
        $artist = Artist::query()->create(['name' => 'XXXTENTACION', 'slug' => 'xxxtentacion', 'genius_id' => 91]);
        Album::query()->create(['artist_id' => $artist->id, 'title' => 'Bad Vibes Forever', 'slug' => 'bad-vibes-forever', 'genius_id' => 123]);
        $parser = app(MuzofondTrackParser::class);
        $sources = (new ReflectionClass($parser))->getMethod('extractTracksFromAlbumPage')->invoke(
            $parser, file_get_contents(base_path('tests/Fixtures/muzofond-bad-vibes-forever.html')), 'XXXTENTACION'
        );
        $this->assertNotNull(collect($sources)->first(fn (ParsedTrack $source) => $source->audioFileId === '3604018'));
        $song = ['id' => 5050894, 'title' => 'introduction (BVF)', 'primary_artist' => ['id' => 91, 'name' => 'XXXTENTACION']];
        $page = new ParsedArtistPage('XXXTENTACION', 'xxxtentacion', null, $sources, [
            ['title' => 'Bad Vibes Forever', 'complete' => true, 'tracks' => $sources],
        ]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $r = new ReflectionClass($service);
        $r->getProperty('albumTracklistResultCache')->setValue($service, [123 => new AlbumTracklistResult([['song' => $song]], true)]);
        $r->getProperty('albumTrackSongCache')->setValue($service, [123 => [$song]]);
        $r->getMethod('createMissingAlbumTracks')->invoke($service, $artist, $page);
        $this->assertSame(0, Track::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_real_search_fixture_fills_the_same_track_and_repeated_job_is_safe(): void
    {
        Http::preventStrayRequests();
        Http::fake(['muzofond.fm/music/*' => Http::response(file_get_contents(base_path('tests/Fixtures/muzofond-cigarette-smoke-search.html')), 200)]);
        $basta = Artist::query()->create(['name' => 'Баста', 'slug' => 'basta', 'genius_id' => 91]);
        $guf = Artist::query()->create(['name' => 'Guf', 'slug' => 'guf', 'genius_id' => 92]);
        $album = Album::query()->create(['artist_id' => $basta->id, 'title' => 'Release', 'slug' => 'release', 'genius_id' => 123]);
        $track = Track::query()->create(['artist_id' => $basta->id, 'album_id' => $album->id, 'title' => 'В сигаретном дыму',
            'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 456]);
        $track->artists()->attach([$basta->id, $guf->id]);
        $track->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 2]);
        $matcher = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $job = new FindMissingTrackAudioJob($track->id);
        $job->handle(app(MuzofondTrackParser::class), $matcher);
        $this->assertSame('9759617', $track->fresh()->muzofond_audio_id);
        $this->assertSame(269, $track->fresh()->duration_seconds);
        $this->assertNotNull($track->fresh()->audio_url);
        $job->handle(app(MuzofondTrackParser::class), $matcher);
        $this->assertSame(1, Track::query()->count());
        Http::assertSentCount(1);
    }

    public function test_audio_search_rejects_different_artist_version_or_explicit_album(): void
    {
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Release', 'slug' => 'release']);
        $track = Track::query()->create(['artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Song',
            'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 42]);
        $track->artists()->attach($artist->id);
        $matcher = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $wrongArtist = new ParsedTrack('Song', 100, 'https://audio.test/1.mp3', null, artistNames: ['Other'], audioFileId: '1');
        $wrongVersion = new ParsedTrack('Song (Live)', 100, 'https://audio.test/2.mp3', null, artistNames: ['Artist'], audioFileId: '2');
        $wrongAlbum = new ParsedTrack('Song', 100, 'https://audio.test/3.mp3', 'Another Release', artistNames: ['Artist'], audioFileId: '3');
        $this->assertNull($matcher->selectMissingTrackAudio($track, [$wrongArtist, $wrongVersion, $wrongAlbum]));
    }

    public function test_audio_search_http_failure_is_not_treated_as_a_successful_miss(): void
    {
        Http::preventStrayRequests();
        Http::fake(['muzofond.fm/music/*' => Http::response('Forbidden', 403)]);
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist']);
        $track = Track::query()->create(['artist_id' => $artist->id, 'title' => 'Song',
            'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 42]);
        $matcher = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        try {
            (new FindMissingTrackAudioJob($track->id))->handle(app(MuzofondTrackParser::class), $matcher);
            $this->fail('A failed request must throw so the queue can retry it.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('403', $exception->getMessage());
            $this->assertNull($track->fresh()->audio_url);
            Http::assertSentCount(2);
        }
    }

    public function test_audio_job_does_not_claim_an_existing_alternate_file(): void
    {
        Http::preventStrayRequests();
        Http::fake(['muzofond.fm/music/*' => Http::response(file_get_contents(base_path('tests/Fixtures/muzofond-cigarette-smoke-search.html')), 200)]);
        $basta = Artist::query()->create(['name' => 'Баста', 'slug' => 'basta']);
        $guf = Artist::query()->create(['name' => 'Guf', 'slug' => 'guf']);
        $track = Track::query()->create(['artist_id' => $basta->id, 'title' => 'В сигаретном дыму', 'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 456]);
        $track->artists()->attach([$basta->id, $guf->id]);
        Track::query()->create(['artist_id' => $basta->id, 'title' => 'Existing', 'duration_seconds' => 269,
            'audio_url' => 'https://audio.test/old.mp3', 'muzofond_alternate_sources' => [['audio_file_id' => '9759617']]]);
        $matcher = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        (new FindMissingTrackAudioJob($track->id))->handle(app(MuzofondTrackParser::class), $matcher);
        $this->assertNull($track->fresh()->audio_url);
    }

    public function test_reparse_fills_existing_placeholder_instead_of_creating_a_duplicate(): void
    {
        $artist = Artist::query()->create(['name' => 'Artist', 'slug' => 'artist', 'genius_id' => 91]);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Release', 'slug' => 'release', 'genius_id' => 123]);
        $placeholder = Track::query()->create(['artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Song',
            'duration_seconds' => null, 'audio_url' => null, 'genius_id' => 456]);
        $placeholder->artists()->attach($artist->id);
        $placeholder->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 1]);
        $song = ['id' => 456, 'title' => 'Song', 'primary_artist' => ['id' => 91, 'name' => 'Artist'],
            'album' => ['id' => 123, 'name' => 'Release']];
        $client = new class extends GeniusClient {
            public function __construct() {}
            public function album(int $id): ?array { return ['id' => $id, 'name' => 'Release', 'artist' => ['id' => 91, 'name' => 'Artist']]; }
            public function artist(int $id): ?array { return ['id' => $id, 'name' => 'Artist']; }
        };
        $service = new GeniusCatalogSyncService($client);
        $r = new ReflectionClass($service);
        $r->getProperty('albumTracklistResultCache')->setValue($service, [123 => new AlbumTracklistResult([['number' => 1, 'song' => $song]], true)]);
        $source = new ParsedTrack('Song', 120, 'https://audio.test/1.mp3', 'Release', 1, ['Artist'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE, audioFileId: '1');
        $this->assertSame($album->id, $r->getMethod('resolveMatchedTrackAlbum')->invoke($service, $artist, $artist, $source, $song)?->id);
        $this->assertNotNull($service->selectMissingTrackAudio($placeholder->fresh(), [$source]));
        $this->assertTrue($r->getMethod('albumTracklistAllows')->invoke($service, $album, $source, 456));
        [$saved, $created] = $r->getMethod('upsertTrackFromGenius')->invoke($service, $artist, $source, $song);
        $this->assertFalse($created);
        $this->assertSame($placeholder->id, $saved->id);
        $this->assertSame('1', $saved->muzofond_audio_id);
        $this->assertSame(120, $saved->duration_seconds);
        $this->assertSame(1, Track::query()->count());
    }
}
