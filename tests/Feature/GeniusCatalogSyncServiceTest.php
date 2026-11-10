<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class GeniusCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_stops_after_first_targeted_query_when_no_candidate_is_found(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Targeted Fallback Artist',
            'slug' => 'targeted-fallback-artist',
            'genius_id' => 908771,
        ]);
        $client = new class() extends GeniusClient {
            /** @var string[] */
            public array $queries = [];

            public function __construct() {}

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                $this->queries[] = $query;

                return [];
            }
        };
        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: $artist->name,
            artistSlug: $artist->slug,
            imageUrl: null,
            tracks: [new ParsedTrack(
                title: 'Targeted fallback title 271',
                durationSeconds: 180,
                audioUrl: 'https://audio.test/targeted-fallback-271.mp3',
                albumTitle: 'Targeted fallback album',
            )],
        );

        $service->syncArtistPage($page);

        $this->assertSame(1, count($client->queries));
    }

    public function test_fallback_keeps_alternate_queries_when_first_query_has_candidates_to_disambiguate(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Candidate Fallback Artist',
            'slug' => 'candidate-fallback-artist',
            'genius_id' => 908773,
        ]);
        $client = new class($artist->name) extends GeniusClient {
            /** @var string[] */
            public array $queries = [];

            public function __construct(private readonly string $artistName) {}

            public function searchSongs(string $query): array
            {
                $this->queries[] = $query;

                if (count($this->queries) !== 1) {
                    return [];
                }

                return [[
                    'id' => 9087731,
                    'title' => 'Candidate fallback title',
                    'primary_artists' => [['name' => $this->artistName]],
                ]];
            }
        };
        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: $artist->name,
            artistSlug: $artist->slug,
            imageUrl: null,
            tracks: [],
        );
        $search = (new ReflectionClass($service))->getMethod('searchSongSummaries');

        $search->invoke(
            $service,
            $page,
            new ParsedTrack(
                title: 'Candidate fallback title',
                durationSeconds: 180,
                audioUrl: 'https://audio.test/candidate-fallback.mp3',
                albumTitle: 'Candidate fallback album',
            ),
            'catalog_has_no_candidates',
        );

        $this->assertGreaterThan(1, count($client->queries));
    }

    public function test_deferred_album_page_track_without_genius_match_completes_its_checkpoint(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Deferred Checkpoint Artist',
            'slug' => 'deferred-checkpoint-artist',
            'genius_id' => 908772,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Source Album',
            'slug' => 'source-album',
        ]);
        $client = new class() extends GeniusClient {
            public function __construct() {}

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }
        };
        $checkpoints = [];
        $service = new GeniusCatalogSyncService($client);
        $service->setProgressCallback(function (array $progress) use (&$checkpoints): void {
            if (is_array($progress['_track_checkpoint'] ?? null)) {
                $checkpoints[] = $progress['_track_checkpoint'];
            }
        });

        $page = new ParsedArtistPage(
            artistName: $artist->name,
            artistSlug: $artist->slug,
            imageUrl: null,
            tracks: [new ParsedTrack(
                title: 'Unmatched Album Track',
                durationSeconds: 180,
                audioUrl: 'https://audio.test/unmatched-album-track.mp3',
                albumTitle: 'Source Album',
                artistNames: [$artist->name],
                sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            )],
        );

        $result = $service->syncArtistPage($page);

        $checkpoint = collect($checkpoints)->last();
        $this->assertIsArray($checkpoint);
        $this->assertTrue($checkpoint['completed']);
        $this->assertFalse($checkpoint['matched']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertSame(1, Track::query()->count());
    }

    public function test_song_artist_filter_accepts_genius_singular_primary_artist_field(): void
    {
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct() {}
        });
        $method = (new ReflectionClass($service))->getMethod('songContainsArtist');

        $this->assertTrue($method->invoke($service, [
            'id' => 4139100,
            'title' => 'Ели мясо мужики',
            'primary_artist' => ['id' => 1381291, 'name' => 'Король и Шут'],
        ], ['Король и Шут']));
    }

    public function test_completed_source_track_checkpoint_skips_repeat_matching_without_duplicate(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Checkpoint Artist',
            'slug' => 'checkpoint-artist',
            'genius_id' => 908772,
        ]);
        $track = Track::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Saved fallback track',
            'muzofond_title' => 'Saved fallback track',
            'duration_seconds' => 180,
            'audio_url' => 'https://audio.test/123456.mp3',
            'muzofond_audio_id' => '123456',
            'genius_id' => null,
        ]);
        $client = new class() extends GeniusClient {
            public int $searchCalls = 0;

            public function __construct() {}

            public function searchSongs(string $query): array
            {
                $this->searchCalls++;

                return [];
            }
        };
        $service = new GeniusCatalogSyncService($client);
        $service->setCompletedTrackOutcomes([
            hash('sha256', 'muzofond-id:123456') => false,
        ]);
        $page = new ParsedArtistPage(
            artistName: $artist->name,
            artistSlug: $artist->slug,
            imageUrl: null,
            tracks: [new ParsedTrack(
                title: 'Saved fallback track',
                durationSeconds: 180,
                audioUrl: 'https://audio.test/123456.mp3',
                albumTitle: null,
                audioFileId: '123456',
            )],
        );

        $result = $service->syncArtistPage($page);

        $this->assertSame(0, $client->searchCalls);
        $this->assertSame(1, Track::query()->count());
        $this->assertSame($track->id, Track::query()->firstOrFail()->id);
        $this->assertSame(1, $result['unmatched_tracks']);
    }

    public function test_matched_alternate_audio_is_consolidated_only_when_track_identity_is_exact(): void
    {
        $artist = Artist::query()->create(['name' => 'PHARAOH', 'slug' => 'pharaoh']);
        $owner = Track::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Champagne Squirt',
            'muzofond_title' => 'Champagne Squirt',
            'muzofond_album_title' => 'Test Single',
            'muzofond_album_appearances' => [
                ['title' => 'Test Single', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 1],
            ],
            'duration_seconds' => 233,
            'audio_url' => 'https://audio.test/7675821.mp3',
            'muzofond_audio_id' => '7675821',
            'genius_id' => 532184,
        ]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $canConsolidate = (new ReflectionClass($service))->getMethod('canConsolidateMatchedSource');

        $appearance = [['title' => 'Test Single', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 1]];
        $sameRecording = new ParsedTrack(
            'Champagne Squirt', 233, 'https://audio.test/2763999.mp3', 'Test Single', 1,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2763999',
            albumAppearances: $appearance,
        );
        $wrongDuration = new ParsedTrack(
            'Champagne Squirt', 232, 'https://audio.test/2763999.mp3', 'Test Single', 1,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2763999',
            albumAppearances: $appearance,
        );
        $wrongPosition = new ParsedTrack(
            'Champagne Squirt', 233, 'https://audio.test/2763999.mp3', 'Test Single', 2,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2763999',
            albumAppearances: [['title' => 'Test Single', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 2]],
        );
        $wrongAlbum = new ParsedTrack(
            'Champagne Squirt', 233, 'https://audio.test/2763999.mp3', 'Other Single', 1,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2763999',
            albumAppearances: [['title' => 'Other Single', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 1]],
        );
        $differentVersion = new ParsedTrack('Champagne Squirt (Remix)', 233, 'https://audio.test/2763999.mp3', 'Test Single', 1, audioFileId: '2763999');

        $this->assertTrue($canConsolidate->invoke($service, $owner, $sameRecording, $artist));
        $this->assertFalse($canConsolidate->invoke($service, $owner, $wrongDuration, $artist));
        $this->assertFalse($canConsolidate->invoke($service, $owner, $wrongPosition, $artist));
        $this->assertFalse($canConsolidate->invoke($service, $owner, $wrongAlbum, $artist));
        $this->assertFalse($canConsolidate->invoke($service, $owner, $differentVersion, $artist));
    }

    public function test_occurrence_matching_reads_the_release_position_from_album_track(): void
    {
        $artist = Artist::query()->create(['name' => 'Position Artist', 'slug' => 'position-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Position Album', 'slug' => 'position-album']);
        $owner = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Position Song',
            'muzofond_title' => 'Position Song',
            'muzofond_album_title' => 'Position Album',
            'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/position-owner.mp3',
            'genius_id' => 77901,
        ]);
        $owner->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 5]);
        $owner->load(['album', 'artists', 'albums']);

        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $canConsolidate = (new ReflectionClass($service))->getMethod('canConsolidateMatchedSource');
        $artistPageSource = new ParsedTrack('Position Song', 200, 'https://audio.test/position-copy.mp3', 'Position Album');
        $samePosition = new ParsedTrack(
            'Position Song', 200, 'https://audio.test/position-copy.mp3', 'Position Album', 5,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
        );
        $differentPosition = new ParsedTrack(
            'Position Song', 200, 'https://audio.test/position-copy.mp3', 'Position Album', 6,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
        );

        $this->assertTrue($canConsolidate->invoke($service, $owner, $artistPageSource, $artist));
        $this->assertTrue($canConsolidate->invoke($service, $owner, $samePosition, $artist));
        $this->assertFalse($canConsolidate->invoke($service, $owner, $differentPosition, $artist));
    }

    public function test_missing_source_position_does_not_merge_into_an_arbitrary_same_title_album_slot(): void
    {
        $artist = Artist::query()->create(['name' => 'Repeated Song Artist', 'slug' => 'repeated-song-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Repeated Song Album', 'slug' => 'repeated-song-album']);
        $first = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Repeated Song',
            'muzofond_title' => 'Repeated Song',
            'muzofond_album_title' => 'Repeated Song Album',
            'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/repeated-one.mp3',
            'genius_id' => 78001,
        ]);
        $second = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Repeated Song',
            'muzofond_title' => 'Repeated Song',
            'muzofond_album_title' => 'Repeated Song Album',
            'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/repeated-two.mp3',
            'genius_id' => 78001,
        ]);
        $first->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 1]);
        $second->albums()->attach($album->id, ['is_primary' => false, 'track_number' => 2]);

        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $findOwner = (new ReflectionClass($service))->getMethod('findExistingOccurrenceOwner');
        $artistPageSource = new ParsedTrack('Repeated Song', 200, 'https://audio.test/new.mp3', 'Repeated Song Album');
        $albumPageSource = new ParsedTrack(
            'Repeated Song', 200, 'https://audio.test/new.mp3', 'Repeated Song Album', 2,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
        );

        $this->assertNull($findOwner->invoke($service, 78001, $artist, $artistPageSource, $album, null));
        $this->assertSame($second->id, $findOwner->invoke($service, 78001, $artist, $albumPageSource, $album, null)?->id);
    }

    public function test_album_page_source_is_preferred_once_for_the_matching_album(): void
    {
        $artist = Artist::query()->create(['name' => 'Pink Phloyd', 'slug' => 'pink-phloyd']);
        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Pink Phloyd',
            'slug' => 'pink-phloyd-album',
        ]);
        $owner = Track::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Дико, например',
            'muzofond_title' => 'Дико, например',
            'duration_seconds' => 168,
            'audio_url' => 'https://audio.test/2807821.mp3',
            'muzofond_audio_id' => '2807821',
            'genius_id' => 2865494,
            'muzofond_album_appearances' => [
                ['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ARTIST_PAGE, 'track_number' => null],
            ],
        ]);
        $source = new ParsedTrack(
            'Дико, например', 168, 'https://audio.test/2835898.mp3', 'Pink Phloyd', 3,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2835898',
            albumAppearances: [['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3]],
        );
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $preferred = (new ReflectionClass($service))->getMethod('incomingAlbumSourceIsPreferred');

        $this->assertTrue($preferred->invoke($service, $owner, $source, $album));

        $owner->muzofond_album_appearances = [
            ['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3],
        ];
        $owner->save();

        $this->assertFalse($preferred->invoke($service, $owner, $source, $album));
    }

    public function test_genius_match_merges_duplicate_audio_into_one_track_and_keeps_both_sources(): void
    {
        $artist = Artist::query()->create(['name' => 'Pink Phloyd', 'slug' => 'pink-phloyd', 'genius_id' => 516658]);
        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Pink Phloyd',
            'slug' => 'pink-phloyd-album',
        ]);
        $owner = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Дико, например',
            'muzofond_title' => 'Дико, например',
            'muzofond_album_title' => 'Pink Phloyd',
            'duration_seconds' => 168,
            'audio_url' => 'https://audio.test/2807821.mp3',
            'original_link' => 'https://audio.test/2807821.mp3',
            'muzofond_audio_id' => '2807821',
            'track_number' => 3,
            'genius_id' => 2865494,
            'genius_url' => 'https://genius.com/pink-phloyd-example-lyrics',
            'muzofond_album_appearances' => [
                ['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3],
            ],
        ]);
        $duplicate = Track::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Дико, например',
            'muzofond_title' => 'Дико, например',
            'muzofond_album_title' => 'Pink Phloyd',
            'duration_seconds' => 168,
            'audio_url' => 'https://audio.test/2835898.mp3',
            'original_link' => 'https://audio.test/2835898.mp3',
            'muzofond_audio_id' => '2835898',
            'track_number' => 3,
            'muzofond_album_appearances' => [
                ['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3],
            ],
        ]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient {
            public function __construct() {}
            public function allArtistAlbums(int $artistId): array { return []; }
            public function album(int $geniusId): ?array { return null; }
            public function albumTracks(int $albumId): array { return []; }
            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array { return []; }
        });
        $upsert = (new ReflectionClass($service))->getMethod('upsertTrackFromGenius');
        $source = new ParsedTrack(
            'Дико, например', 168, 'https://audio.test/2835898.mp3', 'Pink Phloyd', 3,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '2835898',
            albumAppearances: [['title' => 'Pink Phloyd', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3]],
        );

        [$saved, $created] = $upsert->invoke($service, $artist, $source, [
            'id' => 2865494,
            'title' => 'Дико, например',
            'url' => 'https://genius.com/pink-phloyd-example-lyrics',
            'primary_artists' => [['id' => 516658, 'name' => 'Pink Phloyd']],
        ]);

        $this->assertFalse($created);
        $this->assertSame($owner->id, $saved->id);
        $this->assertDatabaseMissing('tracks', ['id' => $duplicate->id]);
        $this->assertSame(1, Track::query()->where('genius_id', 2865494)->count());
        $this->assertSame('2807821', $saved->muzofond_audio_id);
        $this->assertSame('https://audio.test/2807821.mp3', $saved->audio_url);
        $this->assertEqualsCanonicalizing(['2807821', '2835898'], collect($saved->muzofond_alternate_sources)->pluck('audio_file_id')->all());
        $incomingSource = collect($saved->muzofond_alternate_sources)->firstWhere('audio_file_id', '2835898');
        $this->assertSame(168, $incomingSource['duration_seconds']);
        $this->assertSame(3, $incomingSource['track_number']);
        $this->assertSame($album->id, $saved->album_id);
    }

    public function test_fallback_duplicate_with_same_album_position_is_kept_as_json_source(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Example Album', 'slug' => 'example-album']);
        $owner = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Same Song',
            'muzofond_title' => 'Same Song',
            'muzofond_album_title' => 'Example Album',
            'muzofond_album_appearances' => [
                ['title' => 'Example Album', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 4],
            ],
            'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/first.mp3',
            'original_link' => 'https://audio.test/first.mp3',
            'muzofond_audio_id' => '1001',
            'track_number' => 4,
        ]);
        $owner->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 4]);

        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct() {}
        });
        $upsert = (new ReflectionClass($service))->getMethod('upsertTrackFallback');
        $source = new ParsedTrack(
            'Same Song', 200, 'https://audio.test/second.mp3', 'Example Album', 4,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '1002',
            albumAppearances: [['title' => 'Example Album', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 4]],
        );

        [$saved, $created] = $upsert->invoke($service, $artist, $album->id, $source);

        $this->assertFalse($created);
        $this->assertSame($owner->id, $saved->id);
        $this->assertSame(1, Track::query()->count());
        $this->assertSame('1002', collect($saved->muzofond_alternate_sources)->first()['audio_file_id']);
        $this->assertSame(200, collect($saved->muzofond_alternate_sources)->first()['duration_seconds']);
        $this->assertSame(4, collect($saved->muzofond_alternate_sources)->first()['track_number']);
        $this->assertSame(4, (int) $album->tracks()->firstOrFail()->pivot->track_number);
    }

    public function test_album_release_date_uses_genius_components_without_inventing_a_day(): void
    {
        $artist = Artist::query()->create(['name' => 'SALUKI', 'slug' => 'saluki']);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $upsert = (new ReflectionClass($service))->getMethod('upsertAlbumFromGenius');

        [$horrorking] = $upsert->invoke($service, $artist, [
            'id' => 158844,
            'name' => 'HORRORKING',
            'release_date_components' => ['year' => 2016, 'month' => 7, 'day' => 5],
        ]);
        [$deluxe] = $upsert->invoke($service, $artist, [
            'id' => 1706471,
            'name' => 'EUPHORIA DELUXE',
            'release_date_components' => ['year' => 2026, 'month' => 11, 'day' => null],
        ]);

        $this->assertSame('2016-07-05', $horrorking->release_date?->toDateString());
        $this->assertNull($deluxe->release_date);
        $this->assertSame(11, $deluxe->release_date_components['month']);

        [$horrorking] = $upsert->invoke($service, $artist, ['id' => 158844, 'name' => 'HORRORKING']);
        $this->assertSame('2016-07-05', $horrorking->release_date?->toDateString());
    }

    public function test_album_tracklist_rejects_wrong_version_and_extra_position(): void
    {
        $artist = Artist::query()->create(['name' => 'Stromae', 'slug' => 'stromae']);
        $cheese = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Cheese', 'slug' => 'cheese', 'genius_id' => 12924]);
        $multitude = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Multitude', 'slug' => 'multitude', 'genius_id' => 826361]);
        $client = new class extends GeniusClient {
            public function __construct() {}

            public function albumTracks(int $albumId): array
            {
                if ($albumId === 12924) {
                    return [
                        ['number' => 5, 'song' => ['id' => 101, 'title' => 'Alors on danse']],
                        ['number' => 12, 'song' => ['id' => 102, 'title' => "Alors on danse (90's Remix)"]],
                    ];
                }

                return [
                    ['number' => 9, 'song' => ['id' => 201, 'title' => 'Mon amour']],
                ];
            }
        };
        $service = new GeniusCatalogSyncService($client);
        $loadTracklist = (new ReflectionClass($service))->getMethod('albumTrackSongs');
        $loadTracklist->invoke($service, 12924, ['id' => 12924, 'name' => 'Cheese']);
        $loadTracklist->invoke($service, 826361, ['id' => 826361, 'name' => 'Multitude']);
        $allows = (new ReflectionClass($service))->getMethod('albumTracklistAllows');
        $original = new ParsedTrack('Alors on danse', 207, 'https://audio.test/original.mp3', 'Cheese');
        $extra = new ParsedTrack('Mon amour', 187, 'https://audio.test/collaboration.mp3', 'Multitude', 13, sourceType: ParsedTrack::SOURCE_ALBUM_PAGE);

        $this->assertTrue($allows->invoke($service, $cheese, $original, 101));
        $this->assertFalse($allows->invoke($service, $cheese, $original, 102));
        $this->assertFalse($allows->invoke($service, $multitude, $extra, 202));
        $this->assertFalse($allows->invoke($service, $multitude, $extra, null));
    }

    public function test_album_does_not_gain_second_unmarked_copy_of_same_song(): void
    {
        $artist = Artist::query()->create(['name' => 'Stromae', 'slug' => 'stromae']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Multitude', 'slug' => 'multitude']);
        $existing = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Mon amour',
            'muzofond_title' => 'Mon amour',
            'duration_seconds' => 172,
            'audio_url' => 'https://audio.test/original.mp3',
            'muzofond_album_title' => 'Multitude',
            'track_number' => 4,
        ]);
        $existing->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 4]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $allows = (new ReflectionClass($service))->getMethod('albumMembershipHasUniqueVersion');

        $this->assertFalse($allows->invoke($service, $album, new ParsedTrack(
            'Mon amour', 187, 'https://audio.test/other.mp3', 'Multitude', 4,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
        ), null));
        $this->assertTrue($allows->invoke($service, $album, new ParsedTrack('Mon amour (Remix)', 187, 'https://audio.test/remix.mp3', 'Multitude'), null));
        $this->assertTrue($allows->invoke($service, $album, new ParsedTrack('Mon amour', 172, 'https://audio.test/original.mp3', 'Multitude'), $existing));
    }

    public function test_album_remix_does_not_hide_unmatched_original_during_fallback(): void
    {
        $artist = Artist::query()->create(['name' => 'Stromae', 'slug' => 'stromae']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Cheese', 'slug' => 'cheese']);
        Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => "Alors on danse (90's Remix)",
            'muzofond_title' => "Alors on danse (90's Remix)",
            'duration_seconds' => 173,
            'audio_url' => 'https://audio.test/remix.mp3',
            'genius_id' => 902,
        ]);
        $original = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Alors on danse',
            'muzofond_title' => 'Alors on danse',
            'duration_seconds' => 207,
            'audio_url' => 'https://audio.test/original.mp3',
            'track_number' => 5,
        ]);
        $original->albums()->attach($album->id, ['is_primary' => true, 'track_number' => 5]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $sync = (new ReflectionClass($service))->getMethod('syncFallbackTrackAlbumMemberships');

        $sync->invoke($service, $original, $album->id, new ParsedTrack('Alors on danse', 207, 'https://audio.test/original.mp3', 'Cheese'));

        $this->assertTrue($original->albums()->whereKey($album->id)->exists());
        $this->assertSame(5, (int) $original->albums()->first()->pivot->track_number);
    }

    public function test_same_audio_keeps_cheese_and_single_album_positions(): void
    {
        $artist = Artist::query()->create(['name' => 'Stromae', 'slug' => 'stromae']);
        $cheese = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Cheese', 'slug' => 'cheese']);
        $single = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Alors On Danse', 'slug' => 'alors-on-danse']);
        $track = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $cheese->id,
            'title' => 'Alors On Danse',
            'muzofond_title' => 'Alors On Danse',
            'duration_seconds' => 207,
            'audio_url' => 'https://audio.test/original.mp3',
            'track_number' => 5,
            'muzofond_album_appearances' => [
                ['title' => 'Cheese', 'source_type' => ParsedTrack::SOURCE_ARTIST_PAGE, 'track_number' => null],
                ['title' => 'Alors On Danse', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 2],
            ],
        ]);
        $track->albums()->attach($cheese->id, ['is_primary' => true, 'track_number' => 5]);
        $service = new GeniusCatalogSyncService(new class extends GeniusClient { public function __construct() {} });
        $sync = (new ReflectionClass($service))->getMethod('syncFallbackTrackAlbumMemberships');

        $sync->invoke($service, $track, $cheese->id, new ParsedTrack('Alors On Danse', 207, 'https://audio.test/original.mp3', 'Cheese'));

        $memberships = $track->albums()->get()->keyBy('id');
        $this->assertCount(2, $memberships);
        $this->assertSame(5, (int) $memberships[$cheese->id]->pivot->track_number);
        $this->assertSame(2, (int) $memberships[$single->id]->pivot->track_number);
        $this->assertFalse((bool) $memberships[$single->id]->pivot->is_primary);
    }

    public function test_sync_artist_page_falls_back_to_search_and_prefers_oldest_non_demo_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Original Winter',
            'slug' => 'original-winter',
            'cover_image_url' => null,
            'release_date' => '2020-01-10',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [
                    [
                        'id' => 102,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Р РЋР Р…Р ВµР С– Demo'],
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 101,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Original Winter'],
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    ],
                    [
                        'id' => 103,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['name' => 'Original Winter (Deluxe)'],
                        'release_date_components' => ['year' => 2022, 'month' => 5, 'day' => 12],
                    ],
                ];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    101 => [
                        'id' => 101,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 201, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/201'],
                        'release_date' => '2020-01-10',
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                        'song_art_image_url' => 'https://img.test/original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/101',
                    ],
                    102 => [
                        'id' => 102,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 202, 'name' => 'Р РЋР Р…Р ВµР С– Demo', 'url' => 'https://genius.test/albums/202'],
                        'release_date' => '2019-12-01',
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/demo.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/102',
                    ],
                    103 => [
                        'id' => 103,
                        'title' => 'Р РЋР Р…Р ВµР С–',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 203, 'name' => 'Original Winter (Deluxe)', 'url' => 'https://genius.test/albums/203'],
                        'release_date' => '2022-05-12',
                        'release_date_components' => ['year' => 2022, 'month' => 5, 'day' => 12],
                        'song_art_image_url' => 'https://img.test/deluxe.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 200],
                        'url' => 'https://genius.test/songs/103',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    201 => [
                        'id' => 201,
                        'name' => 'Original Winter',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2020-01-10',
                        'cover_art_thumbnail_url' => 'https://img.test/original-cover.jpg',
                    ],
                    202 => [
                        'id' => 202,
                        'name' => 'Р РЋР Р…Р ВµР С– Demo',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2019-12-01',
                        'cover_art_thumbnail_url' => 'https://img.test/demo-cover.jpg',
                    ],
                    203 => [
                        'id' => 203,
                        'name' => 'Original Winter (Deluxe)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2022-05-12',
                        'cover_art_thumbnail_url' => 'https://img.test/deluxe-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    201 => [101 => 2],
                    202 => [102 => 1],
                    203 => [103 => 7],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР Р…Р ВµР С–',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/sneg.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(101, $track->genius_id);
        $this->assertNotSame('', (string) $track->title);
        $this->assertSame('Original Winter', $track->album?->title);
        $this->assertSame('2020-01-10', $track->album?->release_date?->format('Y-m-d'));
    }

    public function test_sync_artist_page_accepts_featured_tracks_even_when_primary_artist_differs(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 201,
                    'title' => 'Р РЋР Р…Р ВµР С–',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'featured_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['name' => 'Original Winter'],
                    'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 201) {
                    return null;
                }

                return [
                    'id' => 201,
                    'title' => 'Р РЋР Р…Р ВµР С–',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'featured_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['id' => 301, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/301'],
                    'release_date' => '2020-01-10',
                    'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    'song_art_image_url' => 'https://img.test/feature.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 120],
                    'url' => 'https://genius.test/songs/201',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 301) {
                    return null;
                }

                return [
                    'id' => 301,
                    'name' => 'Original Winter',
                    'primary_artists' => [['id' => 22, 'name' => 'Other Artist']],
                    'release_date' => '2020-01-10',
                    'cover_art_thumbnail_url' => 'https://img.test/feature-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [201 => 3];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР Р…Р ВµР С–',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/sneg-feature.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);

        $track = Track::query()->with(['artist', 'artists'])->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(201, $track->genius_id);
        $this->assertSame('Other Artist', $track->artist?->name);
        $this->assertEqualsCanonicalizing(['Other Artist', 'Mayot'], $track->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_prefers_album_backed_detail_over_albumless_duplicate(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Original Winter',
            'slug' => 'original-winter-priority',
            'cover_image_url' => null,
            'release_date' => '2020-01-10',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 401,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => null,
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 402,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => null,
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    401 => [
                        'id' => 401,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => null,
                        'release_date' => '2019-12-01',
                        'release_date_components' => ['year' => 2019, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/snow-single.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/401',
                    ],
                    402 => [
                        'id' => 402,
                        'title' => 'Snow',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 501, 'name' => 'Original Winter', 'url' => 'https://genius.test/albums/501'],
                        'release_date' => '2020-01-10',
                        'release_date_components' => ['year' => 2020, 'month' => 1, 'day' => 10],
                        'song_art_image_url' => 'https://img.test/snow-album.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/402',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'name' => 'Original Winter',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-01-10',
                    'cover_art_thumbnail_url' => 'https://img.test/original-winter-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [402 => 5];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Snow',
                    durationSeconds: 200,
                    audioUrl: 'https://audio.test/snow-album-priority.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(402, $track->genius_id);
        $this->assertSame('Original Winter', $track->album?->title);
    }

    public function test_sync_artist_page_prefers_original_album_over_live_release_when_muzofond_album_and_year_match_original(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Cheese',
            'slug' => 'cheese-original',
            'cover_image_url' => null,
            'release_date' => '2010-01-01',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 701,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 1],
                    ],
                    [
                        'id' => 702,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    701 => [
                        'id' => 701,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 801, 'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))', 'url' => 'https://genius.test/albums/801'],
                        'release_date' => '2015-12-01',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 800],
                        'url' => 'https://genius.test/songs/701',
                    ],
                    702 => [
                        'id' => 702,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 802, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/802'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/cheese.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 600],
                        'url' => 'https://genius.test/songs/702',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    801 => [
                        'id' => 801,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-01',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    802 => [
                        'id' => 802,
                        'name' => 'Cheese',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-01-01',
                        'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    801 => [701 => 9],
                    802 => [702 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse',
                    durationSeconds: 207,
                    audioUrl: 'https://audio.test/alors-original.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(702, $track->genius_id);
        $this->assertSame('Cheese', $track->album?->title);
    }

    public function test_sync_artist_page_falls_back_to_album_tracks_when_artist_song_feed_misses_original_album_version(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Cheese',
            'slug' => 'cheese-album-tracks',
            'cover_image_url' => null,
            'release_date' => '2010-06-14',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 801,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Alors On Danse - EP'],
                        'release_date_components' => ['year' => 2010, 'month' => 9, 'day' => 2],
                    ],
                    [
                        'id' => 802,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))'],
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 901,
                        'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))',
                        'url' => 'https://genius.test/albums/901',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-09-26',
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                    ],
                    [
                        'id' => 902,
                        'name' => 'Cheese',
                        'url' => 'https://genius.test/albums/902',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-06-14',
                        'release_date_components' => ['year' => 2010, 'month' => 6, 'day' => 14],
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return match ($albumId) {
                    901 => [[
                        'number' => 9,
                        'song' => [
                            'id' => 802,
                            'title' => 'Alors On Danse',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    902 => [[
                        'number' => 1,
                        'song' => [
                            'id' => 803,
                            'title' => 'Alors On Danse',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    default => [],
                };
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    801 => [
                        'id' => 801,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Alors On Danse - EP', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-09-02',
                        'release_date_components' => ['year' => 2010, 'month' => 9, 'day' => 2],
                        'song_art_image_url' => 'https://img.test/ep.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 400],
                        'url' => 'https://genius.test/songs/801',
                    ],
                    802 => [
                        'id' => 802,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 901, 'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))', 'url' => 'https://genius.test/albums/901'],
                        'release_date' => '2009-09-26',
                        'release_date_components' => ['year' => 2009, 'month' => 9, 'day' => 26],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/802',
                    ],
                    803 => [
                        'id' => 803,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 902, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/902'],
                        'release_date' => '2010-06-14',
                        'release_date_components' => ['year' => 2010, 'month' => 6, 'day' => 14],
                        'song_art_image_url' => 'https://img.test/cheese.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 350],
                        'url' => 'https://genius.test/songs/803',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    901 => [
                        'id' => 901,
                        'name' => 'Р Р†РІвЂљВ¬РЎв„ў (Racine carrР вЂњР’В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-09-26',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    902 => [
                        'id' => 902,
                        'name' => 'Cheese',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-06-14',
                        'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                    ],
                    903 => [
                        'id' => 903,
                        'name' => 'Alors On Danse - EP',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2010-09-02',
                        'cover_art_thumbnail_url' => 'https://img.test/ep-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    901 => [802 => 9],
                    902 => [803 => 1],
                    903 => [801 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse',
                    durationSeconds: 207,
                    audioUrl: 'https://audio.test/alors-from-album-tracks.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(803, $track->genius_id);
        $this->assertSame('Cheese', $track->album?->title);
    }

    public function test_sync_artist_page_keeps_original_track_matched_when_live_variant_has_no_distinct_genius_song(): void
    {
        $artistName = json_decode('"\u041a\u043e\u0440\u043e\u043b\u044c \u0438 \u0428\u0443\u0442"');
        $albumTitle = json_decode('"\u0411\u0443\u0434\u044c \u043a\u0430\u043a \u0434\u043e\u043c\u0430, \u041f\u0443\u0442\u043d\u0438\u043a..."');
        $originalTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a"');
        $liveTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a (Live)"');

        $client = new class($artistName, $albumTitle, $originalTitle) extends GeniusClient {
            public function __construct(
                private readonly string $artistName,
                private readonly string $albumTitle,
                private readonly string $songTitle,
            ) {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 501, 'name' => $this->artistName]];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 501, 'name' => $this->artistName, 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 601,
                    'title' => $this->songTitle,
                    'primary_artists' => [['id' => 501, 'name' => $this->artistName]],
                    'album' => ['id' => 701, 'name' => $this->albumTitle],
                    'release_date_components' => ['year' => 1997, 'month' => 1, 'day' => 1],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 701,
                    'name' => $this->albumTitle,
                    'primary_artists' => [['id' => 501, 'name' => $this->artistName]],
                    'release_date' => '1997-01-01',
                    'release_date_components' => ['year' => 1997, 'month' => 1, 'day' => 1],
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => $this->songTitle,
                    'primary_artists' => [['id' => 501, 'name' => $this->artistName]],
                    'featured_artists' => [],
                    'album' => ['id' => 701, 'name' => $this->albumTitle, 'url' => 'https://genius.test/albums/701'],
                    'release_date' => '1997-01-01',
                    'release_date_components' => ['year' => 1997, 'month' => 1, 'day' => 1],
                    'song_art_image_url' => 'https://img.test/lesnik.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 1000],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 701) {
                    return null;
                }

                return [
                    'id' => 701,
                    'name' => $this->albumTitle,
                    'primary_artists' => [['id' => 501, 'name' => $this->artistName]],
                    'release_date' => '1997-01-01',
                    'cover_art_thumbnail_url' => 'https://img.test/lesnik-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 701 ? [601 => 7] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: $artistName,
            artistSlug: 'korol-i-shut',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: $originalTitle,
                    durationSeconds: 191,
                    audioUrl: 'https://audio.test/lesnik-original.mp3',
                    albumTitle: $albumTitle,
                    trackNumber: 7,
                    artistNames: [$artistName],
                    releaseYear: 1997,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
                new ParsedTrack(
                    title: $liveTitle,
                    durationSeconds: 191,
                    audioUrl: 'https://audio.test/lesnik-live.mp3',
                    albumTitle: $albumTitle,
                    trackNumber: 17,
                    artistNames: [$artistName],
                    releaseYear: 1997,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ARTIST_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $matchedTrack = Track::query()->where('genius_id', 601)->firstOrFail();
        $liveTrack = Track::query()->where('audio_url', 'https://audio.test/lesnik-live.mp3')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertSame($originalTitle, $matchedTrack->title);
        $this->assertSame('https://audio.test/lesnik-original.mp3', $matchedTrack->audio_url);
        $this->assertNull($liveTrack->genius_id);
        $this->assertSame($liveTitle, $liveTrack->title);
    }

    public function test_sync_artist_page_preserves_separate_audio_identity_when_genius_id_is_already_used(): void
    {
        $existingArtist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'genius_id' => 77,
        ]);
        $liveAlbum = Album::query()->create([
            'artist_id' => $existingArtist->id,
            'title' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
            'slug' => 'racine-carree-live',
            'genius_id' => 491687,
            'release_date' => '2015-12-11',
        ]);
        $originalAlbum = Album::query()->create([
            'artist_id' => $existingArtist->id,
            'title' => 'РІв‚¬С™',
            'slug' => 'racine-carree-original',
            'genius_id' => null,
            'release_date' => '2013-08-16',
        ]);
        $existingTrack = Track::query()->create([
            'artist_id' => $existingArtist->id,
            'album_id' => $liveAlbum->id,
            'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
            'duration_seconds' => 216,
            'audio_url' => 'https://audio.test/old-tous.mp3',
            'genius_id' => 203940,
        ]);
        $existingTrack->artists()->sync([$existingArtist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 139735,
                        'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Les leР“В§ons'],
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                    ],
                    [
                        'id' => 203941,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 35659,
                        'name' => 'Les leР“В§ons',
                        'url' => 'https://genius.test/albums/35659',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-01-31',
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                    ],
                    [
                        'id' => 40748,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e)',
                        'url' => 'https://genius.test/albums/40748',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    ],
                    [
                        'id' => 491687,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'url' => 'https://genius.test/albums/491687',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-11',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return match ($albumId) {
                    35659 => [[
                        'number' => 24,
                        'song' => [
                            'id' => 139735,
                            'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    40748 => [[
                        'number' => 8,
                        'song' => [
                            'id' => 203940,
                            'title' => 'Tous les mР“Р„mes',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    491687 => [[
                        'number' => 8,
                        'song' => [
                            'id' => 203941,
                            'title' => 'Tous les mР“Р„mes',
                            'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        ],
                    ]],
                    default => [],
                };
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    139735 => [
                        'id' => 139735,
                        'title' => 'Tous les mР“Р„mes (LeР“В§on nР’В°24)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 35659, 'name' => 'Les leР“В§ons', 'url' => 'https://genius.test/albums/35659'],
                        'release_date' => '2009-01-31',
                        'release_date_components' => ['year' => 2009, 'month' => 1, 'day' => 31],
                        'song_art_image_url' => 'https://img.test/lesson.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 150],
                        'url' => 'https://genius.test/songs/139735',
                    ],
                    203940 => [
                        'id' => 203940,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 40748, 'name' => 'РІв‚¬С™', 'url' => 'https://genius.test/albums/40748'],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                        'song_art_image_url' => 'https://img.test/original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/203940',
                    ],
                    203941 => [
                        'id' => 203941,
                        'title' => 'Tous les mР“Р„mes',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 491687, 'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))', 'url' => 'https://genius.test/albums/491687'],
                        'release_date' => '2015-12-11',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 11],
                        'song_art_image_url' => 'https://img.test/live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 450],
                        'url' => 'https://genius.test/songs/203941',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    35659 => [
                        'id' => 35659,
                        'name' => 'Les leР“В§ons',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2009-01-31',
                        'cover_art_thumbnail_url' => 'https://img.test/lesson-cover.jpg',
                    ],
                    40748 => [
                        'id' => 40748,
                        'name' => 'РІв‚¬С™',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                        'cover_art_thumbnail_url' => 'https://img.test/original-cover.jpg',
                    ],
                    491687 => [
                        'id' => 491687,
                        'name' => 'РІв‚¬С™ (Racine carrР“В©e (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-11',
                        'cover_art_thumbnail_url' => 'https://img.test/live-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    35659 => [139735 => 24],
                    40748 => [203940 => 8],
                    491687 => [203941 => 8],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Tous les mР“Р„mes',
                    durationSeconds: 216,
                    audioUrl: 'https://audio.test/tous-correct.mp3',
                    albumTitle: 'РІв‚¬С™',
                    trackNumber: 8,
                    artistNames: ['Stromae'],
                    releaseYear: 2013,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $existingTrack->refresh();
        $newAudioTrack = Track::query()->where('audio_url', 'https://audio.test/tous-correct.mp3')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(0, $result['unmatched_tracks']);
        $this->assertSame($existingTrack->id, Track::query()->where('genius_id', 203940)->value('id'));
        $this->assertStringStartsWith('Tous les', (string) $existingTrack->title);
        $this->assertSame('https://audio.test/old-tous.mp3', $existingTrack->audio_url);
        $this->assertSame(203940, $existingTrack->genius_id);
        $this->assertSame(203940, $newAudioTrack->genius_id);
        $this->assertSame(2, Track::query()->where('genius_id', 203940)->count());
        $this->assertSame($originalAlbum->id, $newAudioTrack->album_id);
    }

    public function test_sync_artist_page_prefers_version_specific_track_when_parsed_title_includes_remix_marker(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 901,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                    [
                        'id' => 902,
                        'title' => 'Alors On Danse (90\'s Remix)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['name' => 'Cheese'],
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    901 => [
                        'id' => 901,
                        'title' => 'Alors On Danse',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/alors-original.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 300],
                        'url' => 'https://genius.test/songs/901',
                    ],
                    902 => [
                        'id' => 902,
                        'title' => 'Alors On Danse (90\'s Remix)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 903, 'name' => 'Cheese', 'url' => 'https://genius.test/albums/903'],
                        'release_date' => '2010-01-01',
                        'release_date_components' => ['year' => 2010, 'month' => 1, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/alors-remix.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 250],
                        'url' => 'https://genius.test/songs/902',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 903) {
                    return null;
                }

                return [
                    'id' => 903,
                    'name' => 'Cheese',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'release_date' => '2010-01-01',
                    'cover_art_thumbnail_url' => 'https://img.test/cheese-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [901 => 1, 902 => 2];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Alors On Danse (90\'s Remix)',
                    durationSeconds: 173,
                    audioUrl: 'https://audio.test/alors-remix.mp3',
                    albumTitle: 'Cheese',
                    trackNumber: 1,
                    artistNames: ['Stromae'],
                    releaseYear: 2010,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(902, $track->genius_id);
    }

    public function test_sync_artist_page_attaches_unmatched_album_page_track_to_existing_local_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Mayot',
            'slug' => 'mayot',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);
        $existingAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Р В РІР‚вЂќ4Р В РЎСџР В Р’В 4Р В РІР‚в„ўР В РЎв„ў4 Р В РЎв„ўР В Р’ВР В РІР‚Сњ (Р В РІР‚СњР В РІР‚СћР В РІР‚С”Р В Р’В®Р В РЎв„ўР В Р Р‹ Р В РЎСџ4Р В РЎв„ў)',
            'slug' => 'z4-deluxe-pack',
            'cover_image_url' => null,
            'release_date' => '2020-11-20',
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 701,
                    'title' => 'Р РЋР В°Р С',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'album' => ['name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)'],
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 901,
                    'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    'url' => 'https://genius.test/albums/901',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-11-20',
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 701) {
                    return null;
                }

                return [
                    'id' => 701,
                    'title' => 'Р РЋР В°Р С',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'featured_artists' => [],
                    'album' => ['id' => 901, 'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)', 'url' => 'https://genius.test/albums/901'],
                    'release_date' => '2020-11-20',
                    'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                    'song_art_image_url' => 'https://img.test/sam.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 100],
                    'url' => 'https://genius.test/songs/701',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 901) {
                    return null;
                }

                return [
                    'id' => 901,
                    'name' => 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-11-20',
                    'cover_art_thumbnail_url' => 'https://img.test/deluxe-cover.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 901 ? [701 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р РЋР В°Р С',
                    durationSeconds: 170,
                    audioUrl: 'https://audio.test/sam.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
                new ParsedTrack(
                    title: 'Р вЂ”4N',
                    durationSeconds: 168,
                    audioUrl: 'https://audio.test/z4n.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 2,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $matchedTrack = Track::query()->where('genius_id', 701)->with('album')->firstOrFail();
        $fallbackTrack = Track::query()->where('audio_url', 'https://audio.test/z4n.mp3')->with('album')->firstOrFail();

        $this->assertSame(1, $result['matched_tracks']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertNotNull($matchedTrack->album);
        $this->assertSame($matchedTrack->album_id, $fallbackTrack->album_id);
        $this->assertNotSame($existingAlbum->id, $matchedTrack->album_id);
        $this->assertNotSame($existingAlbum->getOriginal('title'), $fallbackTrack->album?->title);
    }

    public function test_sync_artist_page_keeps_unmatched_album_page_track_as_single_when_no_local_album_exists(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р вЂ”4N',
                    durationSeconds: 168,
                    audioUrl: 'https://audio.test/z4n-single.mp3',
                    albumTitle: 'Р вЂ”4Р СџР В 4Р вЂ™Р С™4 Р С™Р ВР вЂќ (Р вЂќР вЂўР вЂєР В®Р С™Р РЋ Р Сџ4Р С™)',
                    trackNumber: 2,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('album')->firstOrFail();

        $this->assertTrue($result['genius_matched']);
        $this->assertSame(1, $result['unmatched_tracks']);
        $this->assertNull($track->album_id);
        $this->assertNull($track->genius_id);
        $this->assertSame(0, Album::query()->count());
    }

    public function test_sync_artist_page_splits_fallback_artist_names_when_track_is_unmatched(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р С™Р С•Р Р…РЎвЂљРЎР‚Р С•Р В»РЎРЉ',
                    durationSeconds: 180,
                    audioUrl: 'https://audio.test/control.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Saluki and Anikv'],
                    releaseYear: null,
                    genres: [],
                ),
            ],
        );

        $result = $service->syncArtistPage($page);
        $track = Track::query()->with('artists')->firstOrFail();

        $this->assertFalse($result['genius_matched']);
        $this->assertTrue(Artist::query()->where('name', 'Saluki')->exists());
        $this->assertTrue(Artist::query()->where('name', 'Anikv')->exists());
        $this->assertFalse(Artist::query()->where('name', 'Saluki and Anikv')->exists());
        $this->assertEqualsCanonicalizing(['Saluki', 'Anikv'], $track->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_prefers_existing_original_album_over_later_live_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Stromae',
            'slug' => 'stromae',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $originalAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Racine carree',
            'slug' => 'racine-carree',
            'cover_image_url' => null,
            'release_date' => '2013-08-16',
            'genius_id' => null,
        ]);

        $liveAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'РІв‚¬С™ (Racine carree (Live))',
            'slug' => 'racine-carree-live',
            'cover_image_url' => null,
            'release_date' => '2015-12-15',
            'genius_id' => 502,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 401,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)'],
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    ],
                    [
                        'id' => 402,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'album' => ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))'],
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 15],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)', 'release_date' => '2013-08-16'],
                    ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))', 'release_date' => '2015-12-15'],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    401 => [
                        'id' => 401,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 501, 'name' => 'РІв‚¬С™ (Racine carree)', 'url' => 'https://genius.test/albums/501'],
                        'release_date' => '2013-08-16',
                        'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                        'song_art_image_url' => 'https://img.test/papaoutai.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 1200],
                        'url' => 'https://genius.test/songs/401',
                    ],
                    402 => [
                        'id' => 402,
                        'title' => 'Papaoutai',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'featured_artists' => [],
                        'album' => ['id' => 502, 'name' => 'РІв‚¬С™ (Racine carree (Live))', 'url' => 'https://genius.test/albums/502'],
                        'release_date' => '2015-12-15',
                        'release_date_components' => ['year' => 2015, 'month' => 12, 'day' => 15],
                        'song_art_image_url' => 'https://img.test/papaoutai-live.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 1800],
                        'url' => 'https://genius.test/songs/402',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    501 => [
                        'id' => 501,
                        'name' => 'РІв‚¬С™ (Racine carree)',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2013-08-16',
                    ],
                    502 => [
                        'id' => 502,
                        'name' => 'РІв‚¬С™ (Racine carree (Live))',
                        'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                        'release_date' => '2015-12-15',
                    ],
                    default => null,
                };
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [401 => 4, 402 => 11];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Papaoutai',
                    durationSeconds: 233,
                    audioUrl: 'https://audio.test/papaoutai.mp3',
                    albumTitle: 'Racine carree',
                    trackNumber: 4,
                    artistNames: ['Stromae'],
                    releaseYear: 2013,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()
            ->with('album')
            ->where('audio_url', 'https://audio.test/papaoutai.mp3')
            ->firstOrFail();

        $this->assertSame('Papaoutai', $track->title);
        $this->assertSame(401, $track->genius_id);
        $this->assertStringContainsString('Racine carree', (string) ($track->album?->title ?? ''));
        $this->assertNotSame($liveAlbum->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_matches_cortisol_via_search_and_reuses_existing_local_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'Saluki',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'CORTISOL',
            'slug' => 'cortisol',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [['id' => 611, 'name' => 'CORTISOL', 'release_date' => '2023-05-19']];
            }

            public function searchSongs(string $query): array
            {
                if (stripos($query, 'cortisol') === false) {
                    return [];
                }

                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 611, 'name' => 'CORTISOL'],
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 611, 'name' => 'CORTISOL', 'url' => 'https://genius.test/albums/611'],
                    'release_date' => '2023-05-19',
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 611) {
                    return null;
                }

                return [
                    'id' => 611,
                    'name' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-05-19',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 611 ? [601 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_reuses_existing_album_context_when_song_detail_points_to_a_different_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'SALUKI',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $album = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'CORTISOL',
            'slug' => 'cortisol',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);
        $album->artists()->sync([$artist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    ['id' => 920, 'name' => 'EUPHORIA', 'release_date' => '2026-03-27'],
                ];
            }

            public function searchSongs(string $query): array
            {
                if (stripos($query, 'cortisol') === false) {
                    return [];
                }

                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => null,
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 920, 'name' => 'EUPHORIA', 'url' => 'https://genius.test/albums/920'],
                    'release_date' => '2026-03-27',
                    'release_date_components' => ['year' => 2026, 'month' => 3, 'day' => 27],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 920) {
                    return null;
                }

                return [
                    'id' => 920,
                    'name' => 'EUPHORIA',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2026-03-27',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'SALUKI',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol-context.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['SALUKI'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(1, Album::query()->count());
        $this->assertSame('CORTISOL', $track->album?->title);
    }

    public function test_resolve_matched_track_album_ignores_polluted_album_page_context_when_context_album_title_differs(): void
    {
        $artist = Artist::query()->create([
            'name' => 'OG Buda',
            'slug' => 'og-buda',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => 1482307,
        ]);

        $wrongAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'FREERIO',
            'slug' => 'freerio-polluted',
            'cover_image_url' => null,
            'release_date' => '2021-04-30',
            'genius_id' => 750060,
        ]);
        $wrongAlbum->artists()->sync([$artist->id]);

        $rightAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'FREERIO 2',
            'slug' => 'freerio-2-correct',
            'cover_image_url' => null,
            'release_date' => '2022-04-29',
            'genius_id' => 768092,
        ]);
        $rightAlbum->artists()->sync([$artist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    768092 => [
                        'id' => 768092,
                        'name' => 'FREERIO 2',
                        'primary_artists' => [['id' => 1482307, 'name' => 'OG Buda']],
                        'release_date' => '2022-04-29',
                        'cover_art_thumbnail_url' => 'https://img.test/freerio-2.jpg',
                    ],
                    default => null,
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $reflection = new ReflectionClass($service);
        $albumPageContextKey = $reflection->getMethod('albumPageContextKey');
        $albumPageContextKey->setAccessible(true);
        $resolveMatchedTrackAlbum = $reflection->getMethod('resolveMatchedTrackAlbum');
        $resolveMatchedTrackAlbum->setAccessible(true);

        $currentSyncArtist = $reflection->getProperty('currentSyncArtist');
        $currentSyncArtist->setAccessible(true);
        $currentSyncArtist->setValue($service, $artist);

        $albumPageAlbumContext = $reflection->getProperty('albumPageAlbumContext');
        $albumPageAlbumContext->setAccessible(true);
        $albumPageAlbumContext->setValue($service, [
            $albumPageContextKey->invoke($service, $artist, 'Freerio 2') => $wrongAlbum->id,
        ]);

        $parsedTrack = new ParsedTrack(
            title: 'Выдох',
            durationSeconds: 133,
            audioUrl: 'https://audio.test/exhale.mp3',
            albumTitle: 'Freerio 2',
            trackNumber: 20,
            artistNames: ['OG Buda', '163ONMYNECK', 'Yanix'],
            releaseYear: 2022,
            genres: [],
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
        );

        $album = $resolveMatchedTrackAlbum->invoke(
            $service,
            $artist,
            $artist,
            $parsedTrack,
            [
                'id' => 7947520,
                'title' => 'Выдох',
                'album' => ['id' => 768092, 'name' => 'FREERIO 2'],
                'primary_artists' => [['id' => 1482307, 'name' => 'OG Buda']],
                'release_date_components' => ['year' => 2022, 'month' => 4, 'day' => 29],
            ],
        );

        $this->assertInstanceOf(Album::class, $album);
        $this->assertSame($rightAlbum->id, $album->id);
    }

    public function test_sync_artist_page_does_not_attach_inferred_secondary_album_memberships(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 920, 'name' => 'EUPHORIA'],
                    'release_date_components' => ['year' => 2026, 'month' => 3, 'day' => 27],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 910,
                        'name' => 'CORTISOL',
                        'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                        'release_date' => '2023-05-19',
                    ],
                    [
                        'id' => 920,
                        'name' => 'EUPHORIA',
                        'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                        'release_date' => '2026-03-27',
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 920, 'name' => 'EUPHORIA', 'url' => 'https://genius.test/albums/920'],
                    'release_date' => '2026-03-27',
                    'release_date_components' => ['year' => 2026, 'month' => 3, 'day' => 27],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    910 => [
                        'id' => 910,
                        'name' => 'CORTISOL',
                        'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                        'release_date' => '2023-05-19',
                    ],
                    920 => [
                        'id' => 920,
                        'name' => 'EUPHORIA',
                        'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                        'release_date' => '2026-03-27',
                    ],
                    default => null,
                };
            }

            public function albumTracks(int $albumId): array
            {
                return match ($albumId) {
                    910, 920 => [[
                        'number' => 1,
                        'song' => [
                            'id' => 601,
                            'title' => 'CORTISOL',
                            'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                        ],
                    ]],
                    default => [],
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    910, 920 => [601 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'SALUKI',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol-single-album.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['SALUKI'],
                    releaseYear: 2026,
                    genres: [],
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with(['album', 'albums'])->firstOrFail();

        $this->assertSame(920, $track->album?->genius_id);
        $this->assertSame([920], $track->albums->pluck('genius_id')->all());
    }

    public function test_sync_artist_page_creates_album_from_genius_when_local_album_is_missing(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [['id' => 611, 'name' => 'CORTISOL', 'release_date' => '2023-05-19']];
            }

            public function searchSongs(string $query): array
            {
                return [[
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 611, 'name' => 'CORTISOL'],
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                ]];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 601) {
                    return null;
                }

                return [
                    'id' => 601,
                    'title' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 611, 'name' => 'CORTISOL', 'url' => 'https://genius.test/albums/611'],
                    'release_date' => '2023-05-19',
                    'release_date_components' => ['year' => 2023, 'month' => 5, 'day' => 19],
                    'song_art_image_url' => 'https://img.test/cortisol.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/601',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 611) {
                    return null;
                }

                return [
                    'id' => 611,
                    'name' => 'CORTISOL',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-05-19',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 611 ? [601 => 1] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'CORTISOL',
                    durationSeconds: 160,
                    audioUrl: 'https://audio.test/cortisol-single.mp3',
                    albumTitle: 'CORTISOL',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->firstOrFail();
        $album = Album::query()->firstOrFail();

        $this->assertSame(601, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame('CORTISOL', $album->title);
        $this->assertSame(1, Album::query()->count());
    }

    public function test_sync_artist_page_prefers_face_artist_over_face_family_noise_from_muzofond(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [
                    ['id' => 1677217, 'name' => 'FACE FAMILY'],
                    ['id' => 988966, 'name' => 'FACE'],
                ];
            }

            public function artist(int $geniusId): ?array
            {
                return match ($geniusId) {
                    1677217 => ['id' => 1677217, 'name' => 'FACE FAMILY', 'alternate_names' => []],
                    988966 => ['id' => 988966, 'name' => 'FACE', 'alternate_names' => []],
                    default => null,
                };
            }

            public function allArtistSongs(int $artistId): array
            {
                return match ($artistId) {
                    1677217 => [[
                        'id' => 1,
                        'title' => 'NOT THIS ONE',
                        'primary_artists' => [['id' => 1677217, 'name' => 'FACE FAMILY']],
                    ]],
                    988966 => [[
                        'id' => 501,
                        'title' => 'Гоша рубчинский',
                        'primary_artists' => [['id' => 988966, 'name' => 'FACE']],
                        'album' => ['id' => 323781, 'name' => 'ПРОКЛЯТАЯ ПЕЧАТЬ (CURSED SEAL)'],
                        'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                    ]],
                    default => [],
                };
            }

            public function allArtistAlbums(int $artistId): array
            {
                return $artistId === 988966
                    ? [[
                        'id' => 323781,
                        'name' => 'ПРОКЛЯТАЯ ПЕЧАТЬ (CURSED SEAL)',
                        'primary_artists' => [['id' => 988966, 'name' => 'FACE']],
                        'release_date' => '2015-10-30',
                    ]]
                    : [];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'title' => 'Гоша рубчинский',
                    'primary_artists' => [['id' => 988966, 'name' => 'FACE']],
                    'featured_artists' => [],
                    'album' => ['id' => 323781, 'name' => 'ПРОКЛЯТАЯ ПЕЧАТЬ (CURSED SEAL)', 'url' => 'https://genius.test/albums/323781'],
                    'release_date' => '2015-10-30',
                    'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                    'song_art_image_url' => 'https://img.test/gosha.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/501',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 323781) {
                    return null;
                }

                return [
                    'id' => 323781,
                    'name' => 'ПРОКЛЯТАЯ ПЕЧАТЬ (CURSED SEAL)',
                    'primary_artists' => [['id' => 988966, 'name' => 'FACE']],
                    'release_date' => '2015-10-30',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 323781 ? [501 => 2] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'FACE FAMILY',
            artistSlug: 'face',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Гоша рубчинский',
                    durationSeconds: 170,
                    audioUrl: 'https://audio.test/gosha-face-family.mp3',
                    albumTitle: null,
                    trackNumber: 2,
                    artistNames: ['FACE FAMILY'],
                    releaseYear: 2015,
                    genres: [],
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('artist')->firstOrFail();

        $this->assertSame('FACE', $track->artist?->name);
        $this->assertSame(988966, $track->artist?->genius_id);
        $this->assertFalse(Artist::query()->whereRaw('LOWER(name) = ?', ['face family'])->exists());
    }

    public function test_genius_artist_id_prevents_similar_seed_artist_from_being_reused(): void
    {
        $face = Artist::query()->create([
            'name' => 'FACE',
            'slug' => 'face',
            'genius_id' => 988966,
        ]);
        $client = new class() extends GeniusClient {
            public function __construct() {}
        };
        $service = new GeniusCatalogSyncService($client);
        $upsert = (new ReflectionClass($service))->getMethod('upsertArtistFromGenius');

        [$faceFamily, $created] = $upsert->invoke($service, $face, [
            'id' => 1677217,
            'name' => 'FACE FAMILY',
            'alternate_names' => ['FACE'],
        ]);

        $this->assertTrue($created);
        $this->assertNotSame($face->id, $faceFamily->id);
        $this->assertSame('FACE', $face->fresh()->name);
        $this->assertSame(988966, $face->fresh()->genius_id);
        $this->assertSame('FACE FAMILY', $faceFamily->name);
        $this->assertSame(1677217, $faceFamily->genius_id);
    }

    public function test_genius_artist_id_does_not_overwrite_an_exact_name_with_a_different_id(): void
    {
        $otherFace = Artist::query()->create([
            'name' => 'FACE',
            'slug' => 'face',
            'genius_id' => 1677217,
        ]);
        $client = new class() extends GeniusClient {
            public function __construct() {}
        };
        $service = new GeniusCatalogSyncService($client);
        $upsert = (new ReflectionClass($service))->getMethod('upsertArtistFromGenius');

        [$face, $created] = $upsert->invoke($service, $otherFace, [
            'id' => 988966,
            'name' => 'FACE',
            'alternate_names' => [],
        ]);

        $this->assertTrue($created);
        $this->assertNotSame($otherFace->id, $face->id);
        $this->assertSame(1677217, $otherFace->fresh()->genius_id);
        $this->assertSame(988966, $face->genius_id);
        $this->assertSame(2, Artist::query()->where('name', 'FACE')->count());
    }

    public function test_sync_artist_page_matches_funny_trolls_and_reuses_album_context_from_muzofond_album_page(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 1381291, 'name' => 'Король и Шут']];
            }

            public function artist(int $geniusId): ?array
            {
                return $geniusId === 1381291
                    ? ['id' => 1381291, 'name' => 'Король и Шут', 'alternate_names' => ['Korol I Shut']]
                    : null;
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 4661173,
                    'title' => 'Весёлые тролли (Funny Trolls)',
                    'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут']],
                    'album' => ['id' => 536102, 'name' => 'Будь как дома, Путник... (Make Yourself at Home, Traveler...)'],
                    'release_date_components' => ['year' => 1997, 'month' => 1, 'day' => 1],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 536102,
                    'name' => 'Будь как дома, Путник... (Make Yourself at Home, Traveler...)',
                    'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут']],
                    'release_date' => '1997-01-01',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 4661173) {
                    return null;
                }

                return [
                    'id' => 4661173,
                    'title' => 'Весёлые тролли (Funny Trolls)',
                    'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут']],
                    'featured_artists' => [],
                    'album' => ['id' => 536102, 'name' => 'Будь как дома, Путник... (Make Yourself at Home, Traveler...)', 'url' => 'https://genius.test/albums/536102'],
                    'release_date' => '1997-01-01',
                    'release_date_components' => ['year' => 1997, 'month' => 1, 'day' => 1],
                    'song_art_image_url' => 'https://img.test/funny-trolls.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 400],
                    'url' => 'https://genius.test/songs/4661173',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 536102) {
                    return null;
                }

                return [
                    'id' => 536102,
                    'name' => 'Будь как дома, Путник... (Make Yourself at Home, Traveler...)',
                    'primary_artists' => [['id' => 1381291, 'name' => 'Король и Шут']],
                    'release_date' => '1997-01-01',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 536102 ? [4661173 => 12] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Король и Шут',
            artistSlug: 'korol-i-shut',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Весёлые тролли',
                    durationSeconds: 161,
                    audioUrl: 'https://audio.test/funny-trolls.mp3',
                    albumTitle: 'Будь как дома, Путник...',
                    trackNumber: 12,
                    artistNames: ['Король и Шут'],
                    releaseYear: 1997,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(4661173, $track->genius_id);
        $this->assertSame(12, $track->track_number);
        $this->assertSame(
            GeniusNameMatcher::albumStorageValue('Будь как дома, Путник... (Make Yourself at Home, Traveler...)'),
            $track->album?->title,
        );
    }

    public function test_sync_artist_page_creates_genius_album_for_matched_face_track_when_local_album_is_missing(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'FACE']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'FACE', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 501,
                    'title' => 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'album' => ['id' => 323781, 'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)'],
                    'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 323781,
                    'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'release_date' => '2015-10-30',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'title' => 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'featured_artists' => [],
                    'album' => ['id' => 323781, 'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)', 'url' => 'https://genius.test/albums/323781'],
                    'release_date' => '2015-10-30',
                    'release_date_components' => ['year' => 2015, 'month' => 10, 'day' => 30],
                    'song_art_image_url' => 'https://img.test/gosha.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/501',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 323781) {
                    return null;
                }

                return [
                    'id' => 323781,
                    'name' => 'РџР РћРљР›РЇРўРђРЇ РџР•Р§РђРўР¬ (CURSED SEAL)',
                    'primary_artists' => [['id' => 77, 'name' => 'FACE']],
                    'release_date' => '2015-10-30',
                    'cover_art_thumbnail_url' => 'https://img.test/cursed-seal.jpg',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 323781 ? [501 => 2] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'FACE',
            artistSlug: 'face',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Р“РѕС€Р° СЂСѓР±С‡РёРЅСЃРєРёР№',
                    durationSeconds: 170,
                    audioUrl: 'https://audio.test/gosha.mp3',
                    albumTitle: null,
                    trackNumber: 2,
                    artistNames: ['FACE'],
                    releaseYear: 2015,
                    genres: [],
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album.artists')->firstOrFail();
        $album = Album::query()->with('artists')->firstOrFail();

        $this->assertSame(501, $track->genius_id);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame(323781, $album->genius_id);
        $this->assertStringNotContainsString('CURSED SEAL', (string) $album->title);
        $this->assertSame(['FACE'], $album->artists->pluck('name')->all());
    }

    public function test_sync_artist_page_keeps_muzofond_album_context_separate_from_different_genius_album(): void
    {
        $artist = Artist::query()->create([
            'name' => 'SALUKI',
            'slug' => 'saluki',
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]);

        $muzofondAlbum = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'SORRY I\'M WORKING ON THE WILD EA$T',
            'slug' => 'sorry-im-working',
            'cover_image_url' => null,
            'release_date' => null,
            'genius_id' => null,
        ]);
        $muzofondAlbum->artists()->sync([$artist->id]);

        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 91, 'name' => 'SALUKI']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 91, 'name' => 'SALUKI', 'alternate_names' => ['Saluki']];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 9013479,
                    'title' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'album' => ['id' => 620708, 'name' => 'WILD EA$T'],
                    'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 21],
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [[
                    'id' => 620708,
                    'name' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-04-21',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 9013479) {
                    return null;
                }

                return [
                    'id' => 9013479,
                    'title' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'featured_artists' => [],
                    'album' => ['id' => 620708, 'name' => 'WILD EA$T', 'url' => 'https://genius.test/albums/620708'],
                    'release_date' => '2023-04-21',
                    'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 21],
                    'song_art_image_url' => 'https://img.test/wild-east.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 500],
                    'url' => 'https://genius.test/songs/9013479',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 620708) {
                    return null;
                }

                return [
                    'id' => 620708,
                    'name' => 'WILD EA$T',
                    'primary_artists' => [['id' => 91, 'name' => 'SALUKI']],
                    'release_date' => '2023-04-21',
                    'cover_art_thumbnail_url' => 'https://img.test/wild-east-cover.jpg',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 620708 ? [9013479 => 2] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Saluki',
            artistSlug: 'saluki',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'WILD EA$T',
                    durationSeconds: 112,
                    audioUrl: 'https://audio.test/wild-east.mp3',
                    albumTitle: 'SORRY I\'M WORKING ON THE WILD EA$T',
                    trackNumber: 1,
                    artistNames: ['Saluki'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();
        $freshMuzofondAlbum = Album::query()->find($muzofondAlbum->id);
        $geniusAlbum = Album::query()->where('genius_id', 620708)->firstOrFail();

        $this->assertSame(9013479, $track->genius_id);
        $this->assertSame($geniusAlbum->id, $track->album_id);
        $this->assertSame('WILD EA$T', $geniusAlbum->title);
        $this->assertNull($freshMuzofondAlbum);
    }

    public function test_sync_artist_page_prefers_original_song_over_demo_variant_with_same_canonical_title(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 5543624,
                        'title' => 'White Force Alert (Demo)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date_components' => ['year' => 2020, 'month' => 5, 'day' => 1],
                    ],
                    [
                        'id' => 5380392,
                        'title' => 'White Force Alert',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['id' => 9301, 'name' => 'Ghetto Garden'],
                        'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 9301,
                        'name' => 'Ghetto Garden',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2020-11-20',
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    5543624 => [
                        'id' => 5543624,
                        'title' => 'White Force Alert (Demo)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'release_date' => '2020-05-01',
                        'release_date_components' => ['year' => 2020, 'month' => 5, 'day' => 1],
                        'song_art_image_url' => 'https://img.test/white-force-demo.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 150],
                        'url' => 'https://genius.test/songs/5543624',
                    ],
                    5380392 => [
                        'id' => 5380392,
                        'title' => 'White Force Alert',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 9301, 'name' => 'Ghetto Garden', 'url' => 'https://genius.test/albums/9301'],
                        'release_date' => '2020-11-20',
                        'release_date_components' => ['year' => 2020, 'month' => 11, 'day' => 20],
                        'song_art_image_url' => 'https://img.test/white-force.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 500],
                        'url' => 'https://genius.test/songs/5380392',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 9301) {
                    return null;
                }

                return [
                    'id' => 9301,
                    'name' => 'Ghetto Garden',
                    'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                    'release_date' => '2020-11-20',
                    'cover_art_thumbnail_url' => 'https://img.test/ghetto-garden.jpg',
                ];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 9301 ? [5380392 => 4] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'White Force Alert (Demo)',
                    durationSeconds: 132,
                    audioUrl: 'https://audio.test/white-force-alert-demo.mp3',
                    albumTitle: null,
                    trackNumber: 1,
                    artistNames: ['Mayot'],
                    releaseYear: 2020,
                    genres: [],
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(5380392, $track->genius_id);
        $this->assertSame('White Force Alert', $track->title);
        $this->assertSame('Ghetto Garden', $track->album?->title);
    }

    public function test_sync_artist_page_does_not_fetch_full_album_track_catalog_when_album_page_already_points_to_original_album(): void
    {
        $client = new class() extends GeniusClient {
            public int $allArtistAlbumsCalls = 0;
            public int $albumTracksCalls = 0;

            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 77, 'name' => 'Stromae']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 77, 'name' => 'Stromae', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [[
                    'id' => 401,
                    'title' => 'Papaoutai',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'album' => ['id' => 501, 'name' => '√ (Racine carree)'],
                    'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    'track_number' => 4,
                ]];
            }

            public function allArtistAlbums(int $artistId): array
            {
                $this->allArtistAlbumsCalls++;

                return [[
                    'id' => 501,
                    'name' => '√ (Racine carree)',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'release_date' => '2013-08-16',
                ]];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                if ($geniusId !== 401) {
                    return null;
                }

                return [
                    'id' => 401,
                    'title' => 'Papaoutai',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'featured_artists' => [],
                    'album' => ['id' => 501, 'name' => '√ (Racine carree)', 'url' => 'https://genius.test/albums/501'],
                    'release_date' => '2013-08-16',
                    'release_date_components' => ['year' => 2013, 'month' => 8, 'day' => 16],
                    'song_art_image_url' => 'https://img.test/papaoutai.jpg',
                    'tags' => [],
                    'stats' => ['pageviews' => 1000],
                    'url' => 'https://genius.test/songs/401',
                ];
            }

            public function album(int $geniusId): ?array
            {
                if ($geniusId !== 501) {
                    return null;
                }

                return [
                    'id' => 501,
                    'name' => '√ (Racine carree)',
                    'primary_artists' => [['id' => 77, 'name' => 'Stromae']],
                    'release_date' => '2013-08-16',
                    'cover_art_thumbnail_url' => 'https://img.test/racine.jpg',
                ];
            }

            public function albumTracks(int $albumId): array
            {
                $this->albumTracksCalls++;

                return [];
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return $albumId === 501 ? [401 => 4] : [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Stromae',
            artistSlug: 'stromae',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Papaoutai',
                    durationSeconds: 233,
                    audioUrl: 'https://audio.test/papaoutai-fast-path.mp3',
                    albumTitle: 'Racine carree',
                    trackNumber: 4,
                    artistNames: ['Stromae'],
                    releaseYear: 2013,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $service->syncArtistPage($page);

        $track = Track::query()->with('album')->firstOrFail();

        $this->assertSame(401, $track->genius_id);
        $this->assertSame(501, $track->album?->genius_id);
        $this->assertSame(1, $client->allArtistAlbumsCalls);
        $this->assertSame(0, $client->albumTracksCalls);
    }

    public function test_sync_artist_page_retries_deferred_album_page_tracks_after_album_context_is_created(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [['id' => 11, 'name' => 'Mayot']];
            }

            public function artist(int $geniusId): ?array
            {
                return ['id' => 11, 'name' => 'Mayot', 'alternate_names' => []];
            }

            public function allArtistSongs(int $artistId): array
            {
                return [
                    [
                        'id' => 7564073,
                        'title' => 'ТЕХНИКА (TECHNIQUE) (INTRO)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot'], ['id' => 12, 'name' => 'Seemee']],
                        'album' => ['id' => 597814, 'name' => 'SCUM OFF THE POT 2'],
                        'release_date_components' => ['year' => 2022, 'month' => 6, 'day' => 24],
                    ],
                    [
                        'id' => 990001,
                        'title' => 'Оба',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'album' => ['id' => 880001, 'name' => 'Оба'],
                        'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 14],
                    ],
                ];
            }

            public function allArtistAlbums(int $artistId): array
            {
                return [
                    [
                        'id' => 880001,
                        'name' => 'Оба',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2023-04-14',
                    ],
                    [
                        'id' => 597814,
                        'name' => 'SCUM OFF THE POT 2',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot'], ['id' => 12, 'name' => 'Seemee']],
                        'release_date' => '2022-06-24',
                    ],
                ];
            }

            public function searchSongs(string $query): array
            {
                return [];
            }

            public function song(int $geniusId): ?array
            {
                return match ($geniusId) {
                    7564073 => [
                        'id' => 7564073,
                        'title' => 'ТЕХНИКА (TECHNIQUE) (INTRO)',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot'], ['id' => 12, 'name' => 'Seemee']],
                        'featured_artists' => [],
                        'album' => ['id' => 597814, 'name' => 'SCUM OFF THE POT 2', 'url' => 'https://genius.test/albums/597814'],
                        'release_date' => '2022-06-24',
                        'release_date_components' => ['year' => 2022, 'month' => 6, 'day' => 24],
                        'song_art_image_url' => 'https://img.test/technique.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 200],
                        'url' => 'https://genius.test/songs/7564073',
                    ],
                    990001 => [
                        'id' => 990001,
                        'title' => 'Оба',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'featured_artists' => [],
                        'album' => ['id' => 880001, 'name' => 'Оба', 'url' => 'https://genius.test/albums/880001'],
                        'release_date' => '2023-04-14',
                        'release_date_components' => ['year' => 2023, 'month' => 4, 'day' => 14],
                        'song_art_image_url' => 'https://img.test/oba.jpg',
                        'tags' => [],
                        'stats' => ['pageviews' => 120],
                        'url' => 'https://genius.test/songs/990001',
                    ],
                    default => null,
                };
            }

            public function album(int $geniusId): ?array
            {
                return match ($geniusId) {
                    880001 => [
                        'id' => 880001,
                        'name' => 'Оба',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot']],
                        'release_date' => '2023-04-14',
                        'cover_art_thumbnail_url' => 'https://img.test/oba-cover.jpg',
                    ],
                    597814 => [
                        'id' => 597814,
                        'name' => 'SCUM OFF THE POT 2',
                        'primary_artists' => [['id' => 11, 'name' => 'Mayot'], ['id' => 12, 'name' => 'Seemee']],
                        'release_date' => '2022-06-24',
                        'cover_art_thumbnail_url' => 'https://img.test/scum-cover.jpg',
                    ],
                    default => null,
                };
            }

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return match ($albumId) {
                    880001 => [990001 => 2],
                    597814 => [7564073 => 1],
                    default => [],
                };
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Mayot',
            artistSlug: 'mayot',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Техника',
                    durationSeconds: 91,
                    audioUrl: 'https://audio.test/oba-technique.mp3',
                    albumTitle: 'Оба',
                    trackNumber: 1,
                    artistNames: ['Mayot', 'Seemee'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
                new ParsedTrack(
                    title: 'Оба',
                    durationSeconds: 140,
                    audioUrl: 'https://audio.test/oba-title-track.mp3',
                    albumTitle: 'Оба',
                    trackNumber: 2,
                    artistNames: ['Mayot'],
                    releaseYear: 2023,
                    genres: [],
                    sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
                ),
            ],
        );

        $result = $service->syncArtistPage($page);

        $techniqueTrack = Track::query()
            ->with('album')
            ->where('audio_url', 'https://audio.test/oba-technique.mp3')
            ->firstOrFail();
        $album = Album::query()->where('genius_id', 880001)->firstOrFail();

        $this->assertSame(2, $result['matched_tracks']);
        $this->assertSame(0, $result['unmatched_tracks']);
        $this->assertSame(7564073, $techniqueTrack->genius_id);
        $this->assertSame($album->id, $techniqueTrack->album_id);
        $this->assertSame('Оба', $techniqueTrack->album?->title);
    }

    public function test_fallback_stores_exact_same_release_recording_as_alternate_source(): void
    {
        $client = new class() extends GeniusClient {
            public function __construct()
            {
            }

            public function searchArtist(string $query): array
            {
                return [];
            }
        };

        $service = new GeniusCatalogSyncService($client);
        $page = new ParsedArtistPage(
            artistName: 'Test Artist',
            artistSlug: 'test-artist',
            imageUrl: null,
            tracks: [
                new ParsedTrack(
                    title: 'Same Song',
                    durationSeconds: 207,
                    audioUrl: 'https://cdn-a.test/track-one.mp3',
                    albumTitle: 'Same Album',
                    artistNames: ['Test Artist'],
                    audioFileId: '3565061',
                ),
                new ParsedTrack(
                    title: 'Same Song',
                    durationSeconds: 207,
                    audioUrl: 'https://cdn-b.test/track-two.mp3',
                    albumTitle: 'Same Album',
                    artistNames: ['Test Artist'],
                    audioFileId: '3565062',
                ),
            ],
        );

        $firstSync = $service->syncArtistPage($page);
        $secondSync = $service->syncArtistPage($page);
        $tracks = Track::query()->orderBy('muzofond_audio_id')->get();

        $this->assertSame(1, $firstSync['tracks']);
        $this->assertSame(0, $secondSync['tracks']);
        $this->assertCount(1, $tracks);
        $this->assertSame('3565061', $tracks->first()->muzofond_audio_id);
        $this->assertSame('https://cdn-a.test/track-one.mp3', $tracks->first()->audio_url);
        $this->assertSame('3565062', collect($tracks->first()->muzofond_alternate_sources)->first()['audio_file_id']);
        $this->assertSame(207, collect($tracks->first()->muzofond_alternate_sources)->first()['duration_seconds']);
    }

    public function test_song_matching_respects_named_versions_and_album_release_versions(): void
    {
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        $reflection = new ReflectionClass($service);
        $titleMatches = $reflection->getMethod('songTitleMatchesParsedTrack');
        $albumConflicts = $reflection->getMethod('sourceAlbumVersionConflictsWithSong');
        $original = new ParsedTrack('Papaoutai', 234, 'https://audio.test/original.mp3', 'Racine carrée');
        $namedRemix = new ParsedTrack('Papaoutai (Nicolaz Remix)', 356, 'https://audio.test/remix.mp3', null);
        $remixAlbum = new ParsedTrack('Papaoutai', 234, 'https://audio.test/album-remix.mp3', 'Racine carrée (Remixes)');

        $this->assertFalse($titleMatches->invoke($service, $original, ['title' => 'Papaoutai (Extended)']));
        $this->assertTrue($titleMatches->invoke($service, $original, ['title' => 'Papaoutai']));
        $this->assertFalse($titleMatches->invoke($service, $namedRemix, ['title' => 'Papaoutai (Remix)']));
        $this->assertTrue($titleMatches->invoke($service, $namedRemix, ['title' => 'Papaoutai (Nicolaz Remix)']));
        $this->assertFalse($titleMatches->invoke($service, new ParsedTrack('Example Song [Liam Remix]', 220, 'https://audio.test/bracket.mp3', null), ['title' => 'Example Song (Remix)']));
        $this->assertFalse($titleMatches->invoke($service, new ParsedTrack('Example Song - Liam Remix', 220, 'https://audio.test/dash.mp3', null), ['title' => 'Example Song (Remix)']));
        $this->assertFalse($titleMatches->invoke($service, new ParsedTrack('Песня (Иванов Ремикс)', 220, 'https://audio.test/ru.mp3', null), ['title' => 'Песня (Ремикс)']));
        $this->assertTrue($albumConflicts->invoke($service, $remixAlbum, [
            'title' => 'Papaoutai',
            'album' => ['name' => 'Racine carrée'],
        ]));
        $this->assertTrue($albumConflicts->invoke($service, $original, [
            'title' => 'Papaoutai',
            'album' => ['name' => 'Racine carrée (Remixes)'],
        ]));
        $this->assertFalse($albumConflicts->invoke($service, $remixAlbum, [
            'title' => 'Papaoutai (Remix)',
            'album' => ['name' => 'Racine carrée'],
        ]));
    }

    public function test_different_duration_muzofond_file_keeps_its_album_membership(): void
    {
        $artist = Artist::query()->create(['name' => 'Test Artist', 'slug' => 'test-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Test Album', 'slug' => 'test-album']);
        $canonical = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Same Song',
            'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/one.mp3',
            'muzofond_audio_id' => '1001',
            'genius_id' => 501,
        ]);
        $canonical->albums()->sync([$album->id => ['is_primary' => true]]);
        $variant = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Same Song',
            'duration_seconds' => 202,
            'audio_url' => 'https://audio.test/two.mp3',
            'muzofond_audio_id' => '1002',
        ]);

        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        $method = (new ReflectionClass($service))->getMethod('syncTrackAlbumMemberships');
        $method->invoke($service, $variant, $album, $artist, new ParsedTrack('Same Song', 202, 'https://audio.test/two.mp3', 'Test Album'), ['id' => 501]);

        $this->assertSame(2, Track::query()->count());
        $this->assertEqualsCanonicalizing([$canonical->id, $variant->id], $album->tracks()->pluck('tracks.id')->all());
    }

    public function test_same_muzofond_album_position_does_not_add_a_second_release_row_even_with_one_second_delta(): void
    {
        $artist = Artist::query()->create(['name' => 'Release Position Artist', 'slug' => 'release-position-artist']);
        $uroboros = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Уроборос: Улица 36',
            'slug' => 'uroboros-ulica-36',
            'genius_id' => 362781,
        ]);
        $upgraded = Album::query()->create([
            'artist_id' => $artist->id,
            'title' => 'UPGRADED',
            'slug' => 'upgraded',
            'genius_id' => 1081642,
        ]);
        $uroborosTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $uroboros->id,
            'title' => 'Животные',
            'muzofond_title' => 'Животные',
            'muzofond_album_title' => 'Уроборос: Улица 36.',
            'duration_seconds' => 182,
            'audio_url' => 'https://audio.test/uroboros-animals.mp3',
            'muzofond_audio_id' => '2954502',
            'genius_id' => 3372508,
        ]);
        $upgradedTrack = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $upgraded->id,
            'title' => 'Животные',
            'muzofond_title' => 'Животные',
            'muzofond_album_title' => 'UPGRADED',
            'muzofond_album_appearances' => [
                ['title' => 'UPGRADED', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 3],
                ['title' => 'Уроборос: Улица 36', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 2],
            ],
            'duration_seconds' => 183,
            'audio_url' => 'https://audio.test/upgraded-animals.mp3',
            'muzofond_audio_id' => '5209422',
            'genius_id' => 10211498,
        ]);
        $uroborosTrack->albums()->attach($uroboros->id, ['is_primary' => true, 'track_number' => 2]);
        $upgradedTrack->albums()->attach($upgraded->id, ['is_primary' => true, 'track_number' => 3]);
        $upgradedTrack->albums()->attach($uroboros->id, ['is_primary' => false, 'track_number' => 2]);

        $service = new GeniusCatalogSyncService(new class extends GeniusClient {
            public function __construct() {}

            public function albumTrackNumbers(int $albumId, ?string $albumUrl = null): array
            {
                return [];
            }
        });
        $syncMemberships = (new ReflectionClass($service))->getMethod('syncTrackAlbumMemberships');
        $source = new ParsedTrack(
            'Животные', 183, 'https://audio.test/upgraded-animals.mp3', 'UPGRADED', 3,
            sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            audioFileId: '5209422',
            albumAppearances: $upgradedTrack->muzofond_album_appearances,
        );

        $syncMemberships->invoke($service, $upgradedTrack, $upgraded, $artist, $source, [
            'id' => 10211498,
            'title' => 'Животные',
            'album' => ['id' => 1081642, 'name' => 'UPGRADED'],
            'albums' => [
                ['id' => 1081642, 'name' => 'UPGRADED'],
                ['id' => 362781, 'name' => 'Уроборос: Улица 36'],
            ],
        ]);

        $this->assertTrue($upgradedTrack->albums()->whereKey($upgraded->id)->exists());
        $this->assertFalse($upgradedTrack->albums()->whereKey($uroboros->id)->exists());
        $this->assertSame([$uroborosTrack->id], $uroboros->tracks()->pluck('tracks.id')->all());
        $this->assertSame(2, Track::query()->count());
    }

    public function test_repeated_parse_rechecks_a_saved_genius_match_against_source_version(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Original Album', 'slug' => 'original-album']);
        $track = Track::query()->create([
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'title' => 'Example Song (Extended)',
            'duration_seconds' => 230,
            'audio_url' => 'https://audio.test/one.mp3',
            'muzofond_audio_id' => '401',
            'genius_id' => 501,
        ]);
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        $method = (new ReflectionClass($service))->getMethod('localGeniusTrackMatchesParsedTrack');
        $source = new ParsedTrack('Example Song', 230, 'https://audio.test/one.mp3', 'Original Album', audioFileId: '401');

        $this->assertFalse($method->invoke($service, $track, $source));
    }

    public function test_fallback_preserves_audio_file_but_removes_proven_wrong_genius_match(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $track = Track::query()->create([
            'artist_id' => $artist->id,
            'title' => 'Example Song (Remix)',
            'duration_seconds' => 230,
            'audio_url' => 'https://audio.test/one.mp3',
            'muzofond_audio_id' => '401',
            'genius_id' => 501,
            'genius_url' => 'https://genius.com/wrong-version',
        ]);
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        $reflection = new ReflectionClass($service);
        $source = new ParsedTrack('Example Song (Named Remix)', 230, 'https://audio.test/one.mp3', null, audioFileId: '401');
        $this->assertFalse($reflection->getMethod('hasExistingGeniusTrackForFallback')->invoke($service, $source));
        $reflection->getMethod('upsertTrackFallback')->invoke($service, $artist, null, $source);

        $track->refresh();
        $this->assertSame('401', $track->muzofond_audio_id);
        $this->assertSame('Example Song (Named Remix)', $track->muzofond_title);
        $this->assertSame('Example Song (Named Remix)', $track->title);
        $this->assertNull($track->genius_id);
        $this->assertNull($track->genius_url);
    }

    public function test_album_reconciliation_keeps_same_title_tracks_with_different_durations(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Example Album', 'slug' => 'example-album']);
        $copy = Track::query()->create([
            'artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Example Song',
            'muzofond_title' => 'Example Song', 'duration_seconds' => 201,
            'audio_url' => 'https://audio.test/one.mp3', 'muzofond_audio_id' => '601',
        ]);
        $copy->albums()->sync([$album->id => ['is_primary' => true]]);
        $canonical = Track::query()->create([
            'artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Example Song',
            'muzofond_title' => 'Example Song', 'duration_seconds' => 200,
            'audio_url' => 'https://audio.test/two.mp3', 'muzofond_audio_id' => '602', 'genius_id' => 701,
        ]);
        $canonical->albums()->sync([$album->id => ['is_primary' => true]]);
        $remix = Track::query()->create([
            'artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Example Song (Named Remix)',
            'muzofond_title' => 'Example Song (Named Remix)', 'duration_seconds' => 240,
            'audio_url' => 'https://audio.test/three.mp3', 'muzofond_audio_id' => '603',
        ]);
        $remix->albums()->sync([$album->id => ['is_primary' => true]]);

        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        (new ReflectionClass($service))->getMethod('reconcileCanonicalAlbumTrackMemberships')->invoke($service, $artist);

        $this->assertSame(3, Track::query()->count());
        $this->assertEqualsCanonicalizing([$copy->id, $canonical->id, $remix->id], $album->tracks()->pluck('tracks.id')->all());
    }

    public function test_unlabeled_duration_outlier_loses_wrong_genius_and_album_links_but_keeps_audio(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        $album = Album::query()->create(['artist_id' => $artist->id, 'title' => 'Example Album', 'slug' => 'example-album']);
        $wrong = Track::query()->create([
            'artist_id' => $artist->id, 'album_id' => $album->id, 'title' => 'Example Song',
            'duration_seconds' => 172, 'audio_url' => 'https://audio.test/short.mp3',
            'original_link' => 'https://audio.test/short.mp3', 'muzofond_audio_id' => '3',
            'muzofond_title' => 'Example Song', 'muzofond_album_title' => 'Example Album',
            'genius_id' => 701, 'genius_url' => 'https://genius.test/original',
        ]);
        $wrong->albums()->sync([$album->id => ['is_primary' => true]]);
        $page = new ParsedArtistPage('Example Artist', 'example-artist', null, [
            new ParsedTrack('Example Song', 206, 'https://audio.test/original.mp3', 'Example Album', audioFileId: '1'),
            new ParsedTrack('Example Song', 211, 'https://audio.test/other.mp3', null, audioFileId: '2'),
            new ParsedTrack('Example Song', 172, 'https://audio.test/short.mp3', 'Example Album', audioFileId: '3'),
            new ParsedTrack('Example Song (90s Remix)', 173, 'https://audio.test/remix.mp3', null, audioFileId: '4'),
        ]);
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });
        $reflection = new ReflectionClass($service);
        $reflection->getMethod('primeSourceAudioVersions')->invoke($service, $artist, $page);

        $this->assertTrue($reflection->getMethod('sourceAudioVersionAmbiguous')->invoke($service, $page->tracks[2]));
        $this->assertNull($reflection->getMethod('findBestSongMatch')->invoke(
            $service,
            $page,
            $page->tracks[2],
            [['id' => 701, 'title' => 'Example Song', 'primary_artists' => [['id' => 1, 'name' => 'Example Artist']]]],
        ));
        $reflection->getMethod('upsertTrackFallback')->invoke($service, $artist, null, $page->tracks[2]);

        $wrong->refresh();
        $this->assertSame('3', $wrong->muzofond_audio_id);
        $this->assertNull($wrong->genius_id);
        $this->assertNull($wrong->album_id);
        $this->assertTrue($wrong->audio_version_ambiguous);
        $this->assertSame(0, $wrong->albums()->count());
    }

    public function test_repeat_parse_keeps_an_explicit_version_title_from_earlier_source_html(): void
    {
        $artist = Artist::query()->create(['name' => 'Example Artist', 'slug' => 'example-artist']);
        Track::query()->create([
            'artist_id' => $artist->id, 'title' => 'Example Song (Named Remix)',
            'muzofond_title' => 'Example Song (Named Remix)',
            'duration_seconds' => 240, 'audio_url' => 'https://audio.test/remix.mp3',
            'muzofond_audio_id' => '9',
        ]);
        $page = new ParsedArtistPage('Example Artist', 'example-artist', null, [
            new ParsedTrack('Example Song', 240, 'https://audio.test/remix.mp3', null, audioFileId: '9'),
        ]);
        $service = new GeniusCatalogSyncService(new class() extends GeniusClient {
            public function __construct()
            {
            }
        });

        (new ReflectionClass($service))->getMethod('primeSourceAudioVersions')->invoke($service, $artist, $page);

        $this->assertSame('Example Song (Named Remix)', $page->tracks[0]->title);
    }
}
