<?php

namespace App\Services\Genius;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\MuzofondAudioIdentity;
use App\Support\ArtworkUrl;
use App\Services\TrackParsing\SourceAudioVersionAnalyzer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GeniusCatalogSyncService
{
    private bool $debugMatchingEnabled = false;

    private ?\Closure $progressCallback = null;

    /** @var array<string, bool> Completed Muzofond source-file keys => Genius matched */
    private array $completedTrackOutcomes = [];

    private bool $geniusUnavailable = false;

    /** @var array<string, int|string> */
    private array $logContext = [];

    private SourceAudioVersionAnalyzer $sourceAudioVersionAnalyzer;

    /** @var array<string, array<string, mixed>> */
    private array $ambiguousSourceAudioVersions = [];

    /**
     * @var string[]|null
     */
    private ?array $trackColumns = null;

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $songDetails = [];

    /** @var array<string, Artist|null> */
    private array $artistCreditNameCache = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $searchSongSummaryCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $albumTrackSongCache = [];

    /**
     * @var array<int, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Album>>
     */
    private array $localArtistAlbums = [];

    /**
     * @var array<string, int>
     */
    private array $albumPageAlbumContext = [];

    /**
     * @var array<string, bool>
     */
    private array $albumPageAlbumContextTrusted = [];

    private ?Artist $currentSyncArtist = null;

    /**
     * @var string[]
     */
    private array $currentPageArtistReferenceNames = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $currentSongExactTitleIndex = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $currentSongLooseTitleIndex = [];

    /**
     * @var array<int, array<string, bool>>
     */
    private array $artistSongTitleCatalogCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $preferredAlbumBySongId = [];

    /**
     * @var array<int, array<string, array<int, array<string, mixed>>>>
     */
    private array $preferredAlbumCandidatesByTitle = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $currentArtistAlbumSummaries = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $currentArtistAlbumTrackSummaries = [];

    private int $currentArtistGeniusId = 0;

    private ?string $fallbackSamplingSeed = null;

    /** @var array<string, int|bool|string|null> */
    private array $fallbackSamplingState = [];

    /** @var array<string, array{summary: array<string, mixed>, detail: array<string, mixed>}|null> */
    private array $fallbackSearchResultsByTrack = [];

    /** @var array<string, bool> Tracks whose Genius album-track lookup failed during this sync */
    private array $fallbackNetworkFailuresByTrack = [];

    /** @var array<int, bool> */
    private array $failedAlbumTrackLookupAlbumIds = [];

    private bool $fallbackSamplingConfigured = false;

    public function __construct(
        private readonly GeniusClient $geniusClient,
    ) {
        $this->sourceAudioVersionAnalyzer = new SourceAudioVersionAnalyzer();
    }


    public function setNeedDebug(bool $debugMatchingEnabled): void
    {
        $this->debugMatchingEnabled = $debugMatchingEnabled;
    }

    /** @param array<string, int|string> $context */
    public function setLogContext(array $context): void
    {
        $this->logContext = $context;
        $this->geniusClient->setLogContext($context);
    }

    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback === null ? null : \Closure::fromCallable($callback);
    }

    /** @return array<string, mixed> */
    public function geniusMetrics(): array
    {
        return $this->geniusClient->metrics();
    }

    /** @param array<string, bool> $outcomes */
    public function setCompletedTrackOutcomes(array $outcomes): void
    {
        $this->completedTrackOutcomes = [];

        foreach ($outcomes as $key => $matched) {
            if (is_string($key) && preg_match('/^[a-f0-9]{64}$/', $key) === 1) {
                $this->completedTrackOutcomes[$key] = (bool) $matched;
            }
        }
    }

    /**
     * Configure deterministic fallback sampling for one queued artist task.
     * Existing state is carried across queue retries so completed misses do not
     * reset the artist-level limit.
     *
     * @param array<string, mixed> $state
     */
    public function setFallbackSamplingContext(?string $seed, array $state = []): void
    {
        $this->fallbackSamplingConfigured = true;
        $this->fallbackSamplingSeed = $seed !== null && trim($seed) !== ''
            ? $seed
            : bin2hex(random_bytes(16));
        $this->fallbackSamplingState = $this->defaultFallbackSamplingState();
        $this->fallbackSearchResultsByTrack = [];
        $this->fallbackNetworkFailuresByTrack = [];
        $this->failedAlbumTrackLookupAlbumIds = [];

        foreach ([
            'tracks_attempted',
            'tracks_skipped',
            'tracks_matched',
            'consecutive_misses',
            'third_query_skipped',
            'third_query_preserved_waiting',
            'network_failures',
        ] as $counter) {
            $this->fallbackSamplingState[$counter] = max(0, (int) ($state[$counter] ?? 0));
        }

        $this->fallbackSamplingState['third_query_skip_reason'] = is_string($state['third_query_skip_reason'] ?? null)
            ? $state['third_query_skip_reason']
            : null;
        $this->fallbackSamplingState['miss_limit'] = (int) ($state['miss_limit'] ?? 10) === 30 ? 30 : 10;
        $this->fallbackSamplingState['stopped'] = (bool) ($state['stopped'] ?? false);
        $this->fallbackSamplingState['stop_reason'] = is_string($state['stop_reason'] ?? null)
            ? $state['stop_reason']
            : null;
    }

    /** @return array<string, int|bool|string|null> */
    private function defaultFallbackSamplingState(): array
    {
        return [
            'strategy' => 'random_no_catalog_candidates',
            'tracks_attempted' => 0,
            'tracks_skipped' => 0,
            'tracks_matched' => 0,
            'consecutive_misses' => 0,
            'miss_limit' => 10,
            'initial_miss_limit' => 10,
            'post_match_miss_limit' => 30,
            'stopped' => false,
            'stop_reason' => null,
            'third_query_skipped' => 0,
            'third_query_skip_reason' => null,
            'third_query_preserved_waiting' => 0,
            'network_failures' => 0,
        ];
    }

    /** @param array<string, mixed> $progress */
    private function reportProgress(array $progress): void
    {
        $checkpoint = $progress['_track_checkpoint'] ?? null;
        unset($progress['_track_checkpoint']);

        if (is_array($checkpoint)
            && is_string($checkpoint['source_key'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $checkpoint['source_key']) === 1) {
            if ((bool) ($checkpoint['completed'] ?? true)) {
                $this->completedTrackOutcomes[$checkpoint['source_key']] = (bool) ($checkpoint['matched'] ?? false);
            }

            if ($this->progressCallback !== null) {
                $progress['_track_checkpoint'] = $checkpoint;
            }
        }

        if ($this->progressCallback !== null) {
            $progress['genius_metrics'] = $this->geniusClient->metrics();
            $progress['genius_metrics']['fallback_sampling'] = $this->fallbackSamplingState;
            ($this->progressCallback)($progress);
        }
    }

    private function parsedTrackCheckpointKey(ParsedTrack $track): string
    {
        return MuzofondAudioIdentity::checkpointKey(
            $track->audioUrl,
            $track->audioFileId,
            $track->title,
            $track->durationSeconds,
        );
    }

    private function debugMatchingEnabled(): bool
    {
        return $this->debugMatchingEnabled || (bool) config('services.genius.debug_matching', false);
    }

    private function matchingLog(string $level, string $message, array $context = []): void
    {
        if ($level === 'debug' && ! $this->debugMatchingEnabled()) {
            return;
        }

        Log::channel((string) config('services.genius.log_channel', 'genius_matching'))
            ->log($level, $message, array_merge($this->logContext, $context));
    }

    /**
     * @param  ParsedTrack[]  $tracks
     * @return ParsedTrack[]
     */
    private function sortParsedTracksForMatching(array $tracks): array
    {
        usort($tracks, function (ParsedTrack $left, ParsedTrack $right): int {
            $leftSourcePriority = $left->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE ? 0 : 1;
            $rightSourcePriority = $right->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE ? 0 : 1;

            if ($leftSourcePriority !== $rightSourcePriority) {
                return $leftSourcePriority <=> $rightSourcePriority;
            }

            $leftAlbumPriority = $this->shouldPersistAlbumTitle($left->albumTitle) ? 0 : 1;
            $rightAlbumPriority = $this->shouldPersistAlbumTitle($right->albumTitle) ? 0 : 1;

            if ($leftAlbumPriority !== $rightAlbumPriority) {
                return $leftAlbumPriority <=> $rightAlbumPriority;
            }

            return ((int) ($left->trackNumber ?? 999)) <=> ((int) ($right->trackNumber ?? 999));
        });

        return array_values($tracks);
    }

    /**
     * Randomize only the slots occupied by tracks with no eligible artist-catalog
     * candidates. A stable task seed makes a queue retry use the same order. Tracks
     * are interleaved by album so the initial sample is not clustered on one album.
     *
     * @param ParsedTrack[] $tracks
     * @param array<int, array<string, mixed>> $songSummaries
     * @param int[] $usedSongIds
     * @return ParsedTrack[]
     */
    private function orderNoCatalogCandidateTracks(
        ParsedArtistPage $page,
        array $tracks,
        array $songSummaries,
        array $usedSongIds,
    ): array {
        if (count($tracks) < 2) {
            return $tracks;
        }

        $slots = [];
        $albumGroups = [];
        $seed = $this->fallbackSamplingSeed ?? $page->artistSlug;

        foreach ($tracks as $index => $track) {
            if ($this->sourceAudioVersionAmbiguous($track)) {
                continue;
            }

            $parsedTitle = GeniusNameMatcher::canonicalTrack($track->title);
            if ($parsedTitle === '') {
                continue;
            }

            $parsedAlbumTitle = $this->shouldPersistAlbumTitle($track->albumTitle)
                ? $this->storedAlbumTitle((string) $track->albumTitle)
                : null;
            $trackCandidates = $this->buildSongMatchCandidates(
                $page,
                $track,
                $this->songCatalogCandidatesForTrack($track, $songSummaries),
                $usedSongIds,
                $parsedTitle,
                $parsedAlbumTitle,
            );

            if ($trackCandidates !== []) {
                continue;
            }

            $slots[] = $index;
            $albumKey = $parsedAlbumTitle !== null
                ? 'album:' . GeniusNameMatcher::normalizeLoose($parsedAlbumTitle)
                : 'source:' . $track->sourceType;
            $albumGroups[$albumKey][] = $track;
        }

        if (count($slots) < 2) {
            return $tracks;
        }

        $albumKeys = array_keys($albumGroups);
        usort($albumKeys, static fn (string $left, string $right): int => strcmp(
            hash('sha256', $seed . '|album|' . $left),
            hash('sha256', $seed . '|album|' . $right),
        ));

        foreach ($albumGroups as $albumKey => &$albumTracks) {
            usort($albumTracks, function (ParsedTrack $left, ParsedTrack $right) use ($seed): int {
                $leftRank = hash('sha256', $seed . '|track|' . $this->fallbackSearchResolutionKey($left));
                $rightRank = hash('sha256', $seed . '|track|' . $this->fallbackSearchResolutionKey($right));

                return strcmp($leftRank, $rightRank);
            });
        }
        unset($albumTracks);

        $sampleOrder = [];
        $groupOffsets = array_fill_keys($albumKeys, 0);
        do {
            $addedInRound = false;
            foreach ($albumKeys as $albumKey) {
                $offset = $groupOffsets[$albumKey];
                if (! isset($albumGroups[$albumKey][$offset])) {
                    continue;
                }

                $sampleOrder[] = $albumGroups[$albumKey][$offset];
                $groupOffsets[$albumKey]++;
                $addedInRound = true;
            }
        } while ($addedInRound);

        foreach ($slots as $slotIndex => $trackIndex) {
            $tracks[$trackIndex] = $sampleOrder[$slotIndex];
        }

        $this->matchingLog('info', 'Randomized Genius fallback candidate order prepared.', [
            'artist' => $page->artistName,
            'eligible_track_count' => count($slots),
            'album_group_count' => count($albumGroups),
            'task_seeded' => $this->fallbackSamplingSeed !== null,
        ]);

        return array_values($tracks);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedGeniusArtistPayload(Artist $artist): ?array
    {
        $geniusId = (int) ($artist->genius_id ?? 0);

        if ($geniusId <= 0) {
            return null;
        }

        if (! $this->hasExactReferenceArtistMatch($this->currentPageArtistReferenceNames, [(string) $artist->name])) {
            return null;
        }

        return [
            'id' => $geniusId,
            'name' => (string) $artist->name,
            'image_url' => $artist->image_url,
            'description_preview' => $artist->description_preview,
        ];
    }

    /**
     * Reuse only existing Genius tracks with a unique, exact local identity match.
     * This path updates the Muzofond audio link and duration, leaving Genius metadata intact.
     *
     * @param  ParsedTrack[]  $parsedTracks
     * @return array<string, array{parsed_track: ParsedTrack, track: Track}>
     */
    private function reuseExistingGeniusTracks(Artist $artist, array $parsedTracks): array
    {
        if ((int) ($artist->genius_id ?? 0) <= 0 || $parsedTracks === []) {
            return [];
        }

        $existingTracks = Track::query()
            ->where('genius_id', '>', 0)
            ->where(function ($query) use ($artist): void {
                $query->where('artist_id', $artist->id)
                    ->orWhereHas('artists', fn ($artistQuery) => $artistQuery->where('artists.id', $artist->id));
            })
            ->with(['album', 'albums', 'artists'])
            ->get();

        if ($existingTracks->isEmpty()) {
            return [];
        }

        $matches = [];
        $usedTrackIds = [];

        foreach ($this->sortParsedTracksForMatching($parsedTracks) as $parsedTrack) {
            if (trim((string) $parsedTrack->audioUrl) === '') {
                continue;
            }

            if ($this->sourceAudioVersionAmbiguous($parsedTrack)) {
                continue;
            }

            $candidates = $existingTracks
                ->filter(fn (Track $track): bool => ! isset($usedTrackIds[$track->id])
                    && $this->localGeniusTrackMatchesParsedTrack($track, $parsedTrack))
                ->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $sameAudioReference = $candidates
                ->filter(fn (Track $track): bool => $this->localTrackHasAudioReference($track, $parsedTrack))
                ->values();

            if ($sameAudioReference->count() === 1) {
                $track = $sameAudioReference->first();
            } elseif ($sameAudioReference->count() > 1 || $candidates->count() !== 1) {
                continue;
            } else {
                $track = $candidates->first();
            }

            if (! $track instanceof Track) {
                continue;
            }

            $matches[spl_object_hash($parsedTrack)] = [
                'parsed_track' => $parsedTrack,
                'track' => $track,
            ];
            $usedTrackIds[$track->id] = true;
        }

        $matches = $this->removeLocalTrackAudioUrlConflicts($matches);

        if ($matches === []) {
            return [];
        }

        DB::transaction(function () use ($artist, $matches): void {
            foreach ($matches as $match) {
                $parsedTrack = $match['parsed_track'];
                $track = $match['track'];
                $audioUrl = trim((string) $parsedTrack->audioUrl);
                $payload = ['audio_url' => $audioUrl];
                $storedDuration = (int) ($track->duration_seconds ?? 0);
                $parsedDuration = (int) $parsedTrack->durationSeconds;

                if ($parsedDuration > 0
                    && ($storedDuration <= 0 || abs($storedDuration - $parsedDuration) <= 2)) {
                    $payload['duration_seconds'] = $parsedDuration;
                }

                $audioFileId = MuzofondAudioIdentity::fileIdFromUrl($audioUrl, $parsedTrack->audioFileId);

                if ($audioFileId !== null && $this->trackHasColumn('muzofond_audio_id')) {
                    $payload['muzofond_audio_id'] = $audioFileId;
                }

                if ($this->trackHasColumn('muzofond_title')) {
                    $payload['muzofond_title'] = GeniusNameMatcher::storageValue($parsedTrack->title);
                    if ($parsedTrack->albumTitle !== null) {
                        $payload['muzofond_album_title'] = $this->storedAlbumTitle($parsedTrack->albumTitle);
                    }
                }

                if ($this->trackHasColumn('audio_version_ambiguous')) {
                    $payload['audio_version_ambiguous'] = false;
                }

                if ($this->trackHasColumn('original_link') && ! $track->original_link) {
                    $payload['original_link'] = $audioUrl;
                }

                $payload = $this->preserveExistingAudioFields($track, $payload);
                $track->forceFill($payload);

                if ($track->isDirty()) {
                    $track->save();
                }

                if (! $track->artists->contains('id', $artist->id)) {
                    $track->artists()->syncWithoutDetaching([$artist->id]);
                }
            }
        });

        return $matches;
    }

    /**
     * Audio hashes are computed from downloaded file contents; catalog metadata has no new file hash.
     * Keep existing hashes, and keep local file URLs on downloaded tracks.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preserveExistingAudioFields(Track $track, array $payload): array
    {
        if ($track->exists) {
            unset($payload['audio_hash']);
        }

        if ($track->exists && (bool) $track->is_downloaded) {
            unset($payload['audio_url']);
        }

        return $payload;
    }

    private function localGeniusTrackMatchesParsedTrack(Track $track, ParsedTrack $parsedTrack): bool
    {
        if (! $this->localTrackHasAudioReference($track, $parsedTrack)
            || ! $this->songTitleMatchesParsedTrack($parsedTrack, ['title' => $track->title])) {
            return false;
        }

        $sourceAlbumVersion = $this->albumVersionFamily($parsedTrack->albumTitle);
        $storedAlbumVersion = $this->albumVersionFamily($track->album?->title);

        if ($parsedTrack->albumTitle === null
            && array_intersect(['remix', 'remixes'], $this->versionFlags($parsedTrack->title)) !== []
            && $storedAlbumVersion !== 'remix') {
            return false;
        }

        return $sourceAlbumVersion === $storedAlbumVersion
            || $parsedTrack->albumTitle === null;
    }

    private function localTrackHasAudioReference(Track $track, ParsedTrack $parsedTrack): bool
    {
        return MuzofondAudioIdentity::matchesParsedTrack($track, $parsedTrack);
    }

    private function primeSourceAudioVersions(Artist $artist, ParsedArtistPage $page): void
    {
        $storedTracks = Track::query()
            ->where('artist_id', $artist->id)
            ->get();
        $storedByIdentity = [];
        $comparisonTracks = [];

        foreach ($storedTracks as $storedTrack) {
            foreach (MuzofondAudioIdentity::keysForTrack($storedTrack) as $key) {
                $storedByIdentity[$key] = $storedTrack;
            }

            $sourceUrl = (string) ($storedTrack->original_link ?: $storedTrack->audio_url);
            $comparisonTracks[] = new ParsedTrack(
                title: (string) ($storedTrack->muzofond_title ?: $storedTrack->title),
                durationSeconds: (int) $storedTrack->duration_seconds,
                audioUrl: $sourceUrl,
                albumTitle: $storedTrack->muzofond_album_title,
                audioFileId: $storedTrack->muzofond_audio_id,
            );
        }

        foreach ($page->tracks as $parsedTrack) {
            $storedTrack = null;

            foreach (MuzofondAudioIdentity::keys($parsedTrack->audioUrl, $parsedTrack->audioFileId) as $key) {
                if (isset($storedByIdentity[$key])) {
                    $storedTrack = $storedByIdentity[$key];
                    break;
                }
            }

            if ($storedTrack instanceof Track
                && $this->sourceAudioVersionAnalyzer->hasVersionMarker((string) $storedTrack->muzofond_title)
                && ! $this->sourceAudioVersionAnalyzer->hasVersionMarker($parsedTrack->title)
                && ! ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
                    && $parsedTrack->albumTitle !== null
                    && GeniusNameMatcher::canonicalTrack($parsedTrack->albumTitle) !== GeniusNameMatcher::canonicalTrack($parsedTrack->title))) {
                $parsedTrack->title = (string) $storedTrack->muzofond_title;
            }

            $comparisonTracks[] = $parsedTrack;
        }

        $this->ambiguousSourceAudioVersions = $this->sourceAudioVersionAnalyzer->ambiguousFiles($comparisonTracks);
    }

    private function sourceAudioVersionAmbiguous(ParsedTrack $track): bool
    {
        $identity = $this->sourceAudioVersionAnalyzer->identity($track);

        return $identity !== null && isset($this->ambiguousSourceAudioVersions[$identity]);
    }

    /**
     * @param  array<string, array{parsed_track: ParsedTrack, track: Track}>  $matches
     * @return array<string, array{parsed_track: ParsedTrack, track: Track}>
     */
    private function removeLocalTrackAudioUrlConflicts(array $matches): array
    {
        $matchesByIdentity = [];
        $candidateIds = [];
        $candidateUrls = [];

        foreach ($matches as $key => $match) {
            $parsedTrack = $match['parsed_track'];
            $identityKeys = MuzofondAudioIdentity::keys($parsedTrack->audioUrl, $parsedTrack->audioFileId);

            foreach ($identityKeys as $identityKey) {
                $matchesByIdentity[$identityKey][] = $key;
            }

            $fileId = MuzofondAudioIdentity::fileIdFromUrl($parsedTrack->audioUrl, $parsedTrack->audioFileId);

            if ($fileId !== null) {
                $candidateIds[] = $fileId;
            }

            foreach ([$parsedTrack->audioUrl, MuzofondAudioIdentity::canonicalUrl($parsedTrack->audioUrl)] as $url) {
                $url = trim((string) $url);

                if ($url !== '') {
                    $candidateUrls[] = $url;
                }
            }
        }

        foreach ($matchesByIdentity as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    unset($matches[$key]);
                }
            }
        }

        $candidateIds = array_values(array_unique($candidateIds));
        $candidateUrls = array_values(array_unique($candidateUrls));

        if ($matches === [] || ($candidateIds === [] && $candidateUrls === [])) {
            return $matches;
        }

        $hasOriginalLink = $this->trackHasColumn('original_link');
        $hasAudioId = $this->trackHasColumn('muzofond_audio_id');
        $owners = Track::query()
            ->where(function ($query) use ($candidateIds, $candidateUrls, $hasOriginalLink, $hasAudioId): void {
                if ($hasAudioId && $candidateIds !== []) {
                    $query->orWhereIn('muzofond_audio_id', $candidateIds);
                }

                if ($candidateUrls !== []) {
                    $query->orWhereIn('audio_url', $candidateUrls);

                    if ($hasOriginalLink) {
                        $query->orWhereIn('original_link', $candidateUrls);
                    }
                }
            })
            ->get(['id', 'audio_url', ...($hasOriginalLink ? ['original_link'] : []), ...($hasAudioId ? ['muzofond_audio_id'] : [])]);

        foreach ($matches as $key => $match) {
            $trackId = (int) $match['track']->id;
            $hasOtherOwner = $owners->contains(function (Track $owner) use ($match, $trackId): bool {
                return (int) $owner->id !== $trackId
                    && MuzofondAudioIdentity::matchesParsedTrack($owner, $match['parsed_track']);
            });

            if ($hasOtherOwner) {
                unset($matches[$key]);
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @return array<int, array<string, mixed>>
     */
    private function preloadRelevantAlbumTrackSummaries(ParsedArtistPage $page, array $albumSummaries): array
    {
        if ($albumSummaries === []) {
            return [];
        }

        $summaries = [];
        $albumPayloads = [];

        foreach ($this->sortParsedTracksForMatching($page->tracks) as $parsedTrack) {
            if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
                continue;
            }

            $albumCandidates = $this->buildAlbumTrackFallbackCandidates($parsedTrack, $albumSummaries);

            if ($albumCandidates === []) {
                continue;
            }

            if ($this->shouldSkipAlbumTrackPreloadForParsedTrack($parsedTrack, $albumCandidates)) {
                continue;
            }

            $inspectionLimit = min(
                12,
                max(6, $this->albumTrackFallbackInspectionLimit($albumCandidates) + 2)
            );

            foreach (array_slice($albumCandidates, 0, $inspectionLimit) as $albumCandidate) {
                $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
                $albumId = (int) ($albumPayload['id'] ?? 0);

                if ($albumId <= 0 || isset($albumPayloads[$albumId])) {
                    continue;
                }

                $albumPayloads[$albumId] = $albumPayload;
            }
        }

        try {
            $tracksByAlbum = $this->geniusClient->albumTracksMany(array_keys($albumPayloads));
        } catch (Throwable $exception) {
            $this->matchingLog('warning', 'Genius album-track batch preload failed; continuing with artist catalog candidates.', [
                'artist' => $page->artistName,
                'album_ids' => array_keys($albumPayloads),
                'exception' => $exception->getMessage(),
            ]);
            $tracksByAlbum = [];
        }

        foreach ($albumPayloads as $albumId => $albumPayload) {
            $songs = $this->normalizeAlbumTrackSongs(
                (int) $albumId,
                $albumPayload,
                $tracksByAlbum[(int) $albumId] ?? [],
            );
            $this->albumTrackSongCache[(int) $albumId] = $songs;

            if ($songs !== []) {
                $summaries = $this->mergeSongSummaries($summaries, $songs);
            }
        }

        $this->matchingLog('debug', 'Genius album-track summaries preloaded.', [
            'artist' => $page->artistName,
            'matched_album_count' => count($albumPayloads),
            'preloaded_song_summary_count' => count($summaries),
        ]);

        return $summaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @return array<int, array<string, mixed>>
     */
    private function preloadAllArtistAlbumTrackSummaries(ParsedArtistPage $page, array $albumSummaries): array
    {
        if ($albumSummaries === []) {
            return [];
        }

        $summaries = [];
        $albumCount = 0;

        foreach ($albumSummaries as $albumSummary) {
            if (! is_array($albumSummary)) {
                continue;
            }

            $albumId = (int) ($albumSummary['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            $albumCount++;
            $songs = $this->albumTrackSongs($albumId, $albumSummary);

            if ($songs === []) {
                continue;
            }

            $summaries = $this->mergeSongSummaries($summaries, $songs);
        }

        $this->matchingLog('debug', 'Genius full artist album-track catalog primed.', [
            'artist' => $page->artistName,
            'album_count' => $albumCount,
            'album_track_summary_count' => count($summaries),
        ]);

        return $summaries;
    }

    /**
     * @return array{artist:Artist, artists:int, albums:int, tracks:int, matched_tracks:int, unmatched_tracks:int, genius_matched:bool, genius_unavailable:bool}
     */
    public function syncArtistPage(ParsedArtistPage $page): array
    {
        $this->geniusClient->beginRun();
        if (! $this->fallbackSamplingConfigured) {
            $this->fallbackSamplingSeed = bin2hex(random_bytes(16));
            $this->fallbackSamplingState = $this->defaultFallbackSamplingState();
        }
        $this->fallbackSearchResultsByTrack = [];
        $this->fallbackNetworkFailuresByTrack = [];
        $this->failedAlbumTrackLookupAlbumIds = [];
        $this->albumPageAlbumContext = [];
        $this->albumPageAlbumContextTrusted = [];
        $this->songDetails = [];
        $this->searchSongSummaryCache = [];
        $this->albumTrackSongCache = [];
        $this->localArtistAlbums = [];
        $this->currentSyncArtist = null;
        $this->currentPageArtistReferenceNames = $this->pageArtistReferenceNames($page);
        $this->currentSongExactTitleIndex = [];
        $this->currentSongLooseTitleIndex = [];
        $this->preferredAlbumBySongId = [];
        $this->preferredAlbumCandidatesByTitle = [];
        $this->currentArtistAlbumSummaries = [];
        $this->currentArtistAlbumTrackSummaries = [];
        $this->artistSongTitleCatalogCache = [];
        $this->currentArtistGeniusId = 0;
        $this->geniusUnavailable = false;
        $this->ambiguousSourceAudioVersions = [];

        $storedArtistName = $this->preferredPageArtistStorageName($page);
        $existingArtist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedArtistName)])
            ->first();
        $storedGeniusArtist = $existingArtist instanceof Artist
            ? $this->storedGeniusArtistPayload($existingArtist)
            : null;
        try {
            $geniusArtist = $storedGeniusArtist ?? $this->matchArtist($page);
        } catch (Throwable $exception) {
            $this->geniusUnavailable = true;
            $this->matchingLog('warning', 'Genius artist lookup failed; saving Muzofond tracks as fallback.', [
                'artist' => $page->artistName,
                'artist_slug' => $page->artistSlug,
                'exception' => $exception->getMessage(),
            ]);
            $geniusArtist = null;
        }

        // Persist the source catalog after the Genius lookup resolves, including API outages.
        [$artist, $artistCreated] = $this->upsertPrimaryArtistFromMuzofond($page);
        $this->currentSyncArtist = $artist;
        $this->primeSourceAudioVersions($artist, $page);
        $artistsCreated = $artistCreated ? 1 : 0;
        $albumsCreated = 0;
        $tracksCreated = 0;
        $matchedTracks = 0;
        $unmatchedTracks = 0;

        if (! $geniusArtist) {
            $tracksCreated += $this->persistMuzofondFallbackTracks($artist, $page);

            return [
                'artist' => $artist,
                'artists' => $artistsCreated,
                'albums' => $albumsCreated,
                'tracks' => $tracksCreated,
                'matched_tracks' => 0,
                'unmatched_tracks' => count($page->tracks),
                'genius_matched' => false,
                'genius_unavailable' => $this->geniusUnavailable,
            ];
        }

        if ($storedGeniusArtist !== null) {
            $artistWasCreatedFromGenius = false;
        } else {
            [$artist, $artistWasCreatedFromGenius] = $this->upsertArtistFromGenius($artist, $geniusArtist);
        }

        $this->currentSyncArtist = $artist;
        $this->currentArtistGeniusId = (int) ($geniusArtist['id'] ?? 0);
        $artistsCreated += $artistWasCreatedFromGenius ? 1 : 0;

        $pageTrackKeys = [];
        $pageCheckpointMatches = [];
        foreach ($page->tracks as $parsedTrack) {
            $key = $this->parsedTrackCheckpointKey($parsedTrack);
            $pageTrackKeys[spl_object_hash($parsedTrack)] = $key;
            if (array_key_exists($key, $this->completedTrackOutcomes)) {
                $pageCheckpointMatches[$key] = $this->completedTrackOutcomes[$key];
            }
        }
        $uncheckpointedTracks = array_values(array_filter(
            $page->tracks,
            fn (ParsedTrack $parsedTrack): bool => ! array_key_exists(
                $pageTrackKeys[spl_object_hash($parsedTrack)],
                $pageCheckpointMatches,
            ),
        ));

        $localTrackMatches = $this->reuseExistingGeniusTracks($artist, $uncheckpointedTracks);
        $checkpointMatchedCount = count(array_filter($pageCheckpointMatches));
        $checkpointUnmatchedCount = count($pageCheckpointMatches) - $checkpointMatchedCount;
        $matchedTracks = count($localTrackMatches) + $checkpointMatchedCount;
        $unmatchedTracks = $checkpointUnmatchedCount;
        $baseProcessedTracks = count($localTrackMatches) + count($pageCheckpointMatches);
        $usedSongIds = collect($localTrackMatches)
            ->map(fn (array $match) => (int) ($match['track']->genius_id ?? 0))
            ->filter(fn (int $songId) => $songId > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($localTrackMatches as $match) {
            $this->rememberAlbumPageContextFromTrack($artist, $match['parsed_track'], $match['track']);
            $this->reportProgress([
                'tracks_total' => count($page->tracks),
                'tracks_processed' => $baseProcessedTracks,
                'matched_tracks' => $matchedTracks,
                'unmatched_tracks' => $unmatchedTracks,
                'current_track' => GeniusNameMatcher::storageValue($match['parsed_track']->title),
                '_track_checkpoint' => [
                    'source_key' => $pageTrackKeys[spl_object_hash($match['parsed_track'])],
                    'track_title' => GeniusNameMatcher::storageValue($match['parsed_track']->title),
                    'matched' => true,
                ],
            ]);
        }

        $localTrackHashes = array_fill_keys(array_keys($localTrackMatches), true);
        $remainingTracks = array_values(array_filter(
            $uncheckpointedTracks,
            fn (ParsedTrack $parsedTrack): bool => ! isset($localTrackHashes[spl_object_hash($parsedTrack)]),
        ));

        $remainingPage = new ParsedArtistPage(
            artistName: $page->artistName,
            artistSlug: $page->artistSlug,
            imageUrl: $page->imageUrl,
            tracks: $remainingTracks,
        );

        if ($remainingTracks === []) {
            $this->reconcileCanonicalAlbumTrackMemberships($artist);

            return [
                'artist' => $artist,
                'artists' => $artistsCreated,
                'albums' => $albumsCreated,
                'tracks' => $tracksCreated,
                'matched_tracks' => $matchedTracks,
                'unmatched_tracks' => $unmatchedTracks,
                'genius_matched' => true,
                'genius_unavailable' => false,
            ];
        }

        $this->primeAlbumPageAlbumContext($artist, $remainingPage);
        $deferredAlbumPageTracks = [];
        $deferredAlbumPageTrackMatchedState = [];
        $orderedParsedTracks = $this->sortParsedTracksForMatching($remainingTracks);

        try {
            $albumSummaries = collect($this->geniusClient->allArtistAlbums((int) $geniusArtist['id']))
                ->filter(fn ($album) => is_array($album))
                ->values()
                ->all();
            $this->currentArtistAlbumSummaries = $albumSummaries;

            $songSummaries = collect($this->geniusClient->allArtistSongs((int) $geniusArtist['id']))
                ->reject(function (array $song): bool {
                    $title = GeniusNameMatcher::normalizeLoose((string) ($song['title'] ?? ''));

                    return str_contains($title, 'romanized') || str_contains($title, 'translation');
                })
                ->values()
                ->all();

            // Full artist album-track preload blows up memory on large catalogs like MAYOT.
            // Keep only the relevant album-track pages for album-page rows from Muzofond.
            $preloadedAlbumTrackSummaries = $this->preloadRelevantAlbumTrackSummaries($remainingPage, $albumSummaries);
            $this->currentArtistAlbumTrackSummaries = $preloadedAlbumTrackSummaries;

            if ($preloadedAlbumTrackSummaries !== []) {
                $songSummaries = $this->mergeSongSummaries($songSummaries, $preloadedAlbumTrackSummaries);
            }

            $this->primeSongSummaryIndexes($songSummaries);
            $this->prefetchSongDetailsForTracks($remainingPage, $orderedParsedTracks, $songSummaries);
        } catch (Throwable $exception) {
            $this->geniusUnavailable = true;
            $this->matchingLog('warning', 'Genius catalog lookup failed; saving remaining Muzofond tracks as fallback.', [
                'artist' => $page->artistName,
                'artist_slug' => $page->artistSlug,
                'remaining_track_count' => count($remainingTracks),
                'exception' => $exception->getMessage(),
            ]);
            $tracksCreated += $this->persistMuzofondFallbackTracks($artist, $remainingPage);

            return [
                'artist' => $artist,
                'artists' => $artistsCreated,
                'albums' => $albumsCreated,
                'tracks' => $tracksCreated,
                'matched_tracks' => $matchedTracks,
                'unmatched_tracks' => count($remainingTracks),
                'genius_matched' => $storedGeniusArtist !== null || $geniusArtist !== null,
                'genius_unavailable' => true,
            ];
        }

        $this->matchingLog('debug', 'Genius artist catalog primed for sync.', [
            'artist' => $page->artistName,
            'genius_artist_id' => (int) ($geniusArtist['id'] ?? 0),
            'album_count' => count($albumSummaries),
            'song_summary_count' => count($songSummaries),
            'preloaded_album_track_summary_count' => count($preloadedAlbumTrackSummaries),
        ]);

        $orderedParsedTracks = $this->orderNoCatalogCandidateTracks(
            $page,
            $orderedParsedTracks,
            $songSummaries,
            $usedSongIds,
        );

        foreach ($orderedParsedTracks as $trackIndex => $parsedTrack) {
            $trackPersisted = false;
            $trackMatched = false;
            $trackDeferred = false;
            try {
                $matchedSong = $this->findBestSongMatch(
                    $page,
                    $parsedTrack,
                    $this->songCatalogCandidatesForTrack($parsedTrack, $songSummaries),
                    $usedSongIds,
                    true,
                    $albumSummaries,
                );

                if (! $matchedSong) {
                    $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                    $fallbackAlbumId = $this->sourceAudioVersionAmbiguous($parsedTrack)
                        ? null
                        : $this->resolveFallbackAlbumId($artist, $parsedTrack);
                    [$track, $trackCreated] = $this->upsertTrackFallback($artist, $fallbackAlbumId, $parsedTrack);
                    $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());
                    $trackPersisted = true;

                    if ($this->shouldRetryAlbumPageTrackMatch($parsedTrack)) {
                        $deferredAlbumPageTracks[] = $parsedTrack;
                        $deferredAlbumPageTrackMatchedState[spl_object_hash($parsedTrack)] = false;
                        $trackDeferred = true;
                    }

                    $trackResult = ['matched' => false, 'created' => $trackCreated];
                } else {
                    [$track, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $matchedSong['detail']);
                    $trackPersisted = true;
                    $this->rememberAlbumPageContextFromTrack($artist, $parsedTrack, $track);

                    if ($this->shouldRetryMatchedAlbumPageTrackAfterContextResolution($parsedTrack, $track)) {
                        $deferredAlbumPageTracks[] = $parsedTrack;
                        $deferredAlbumPageTrackMatchedState[spl_object_hash($parsedTrack)] = true;
                        $trackDeferred = true;
                    }

                    $trackResult = ['matched' => true, 'created' => $trackCreated];
                    $trackMatched = true;
                }

                if ($matchedSong && (int) ($matchedSong['summary']['id'] ?? 0) > 0) {
                    $usedSongIds[] = (int) $matchedSong['summary']['id'];
                }

                if (! $trackResult['matched']) {
                    $unmatchedTracks++;
                    $tracksCreated += $trackResult['created'] ? 1 : 0;
                    $this->reportProgress([
                        'tracks_total' => count($page->tracks),
                        'tracks_processed' => $baseProcessedTracks + $trackIndex + 1,
                        'matched_tracks' => $matchedTracks,
                        'unmatched_tracks' => $unmatchedTracks,
                        'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                        '_track_checkpoint' => $trackPersisted ? [
                            'source_key' => $pageTrackKeys[spl_object_hash($parsedTrack)],
                            'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                            'matched' => false,
                            'completed' => ! $trackDeferred,
                        ] : null,
                    ]);

                    continue;
                }

                $matchedTracks++;
                $tracksCreated += $trackResult['created'] ? 1 : 0;
            } catch (Throwable $exception) {
                $this->geniusUnavailable = $this->geniusUnavailable || $this->isGeniusRequestFailure($exception);
                $unmatchedTracks++;

                try {
                    $albumId = $this->sourceAudioVersionAmbiguous($parsedTrack)
                        ? null
                        : $this->resolveFallbackAlbumId($artist, $parsedTrack);

                    if ($this->sourceAudioVersionAmbiguous($parsedTrack)
                        || ! $this->hasExistingGeniusTrackForFallback($parsedTrack)) {
                        $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
                        [$track, $trackCreated] = $this->upsertTrackFallback($artist, $albumId, $parsedTrack);
                        $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());
                        $trackPersisted = true;
                        $tracksCreated += $trackCreated ? 1 : 0;
                    }
                } catch (Throwable $fallbackException) {
                    $this->matchingLog('warning', 'Fallback persistence also failed after Genius sync exception.', [
                        'artist' => $page->artistName,
                        'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                        'exception' => $fallbackException->getMessage(),
                    ]);
                }

                $this->matchingLog('warning', 'Genius sync skipped one track because of an exception.', [
                    'artist' => $page->artistName,
                    'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                    'exception' => $exception->getMessage(),
                ]);
            }

            $this->reportProgress([
                'tracks_total' => count($page->tracks),
                'tracks_processed' => $baseProcessedTracks + $trackIndex + 1,
                'matched_tracks' => $matchedTracks,
                'unmatched_tracks' => $unmatchedTracks,
                'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                '_track_checkpoint' => $trackPersisted ? [
                    'source_key' => $pageTrackKeys[spl_object_hash($parsedTrack)],
                    'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                    'matched' => $trackMatched,
                    'completed' => ! $trackDeferred,
                ] : null,
            ]);
        }

        if ($deferredAlbumPageTracks !== []) {
            $this->primeAlbumPageAlbumContext($artist, new ParsedArtistPage(
                artistName: $page->artistName,
                artistSlug: $page->artistSlug,
                imageUrl: $page->imageUrl,
                tracks: $deferredAlbumPageTracks,
            ));

            foreach ($deferredAlbumPageTracks as $parsedTrack) {
                if (! $this->resolveAlbumPageContextAlbum($artist, $parsedTrack) instanceof Album) {
                    $this->reportProgress([
                        'tracks_total' => count($page->tracks),
                        'tracks_processed' => count($page->tracks),
                        'matched_tracks' => $matchedTracks,
                        'unmatched_tracks' => $unmatchedTracks,
                        'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                        '_track_checkpoint' => [
                            'source_key' => $pageTrackKeys[spl_object_hash($parsedTrack)],
                            'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                            'matched' => (bool) ($deferredAlbumPageTrackMatchedState[spl_object_hash($parsedTrack)] ?? false),
                            'completed' => true,
                        ],
                    ]);
                    continue;
                }

                try {
                    $matchedSong = $this->findBestSongMatch(
                        $page,
                        $parsedTrack,
                        $this->songCatalogCandidatesForTrack($parsedTrack, $songSummaries),
                        $usedSongIds,
                        true,
                        $albumSummaries,
                    );

                    if (! $matchedSong) {
                        $this->reportProgress([
                            'tracks_total' => count($page->tracks),
                            'tracks_processed' => count($page->tracks),
                            'matched_tracks' => $matchedTracks,
                            'unmatched_tracks' => $unmatchedTracks,
                            'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                            '_track_checkpoint' => [
                                'source_key' => $pageTrackKeys[spl_object_hash($parsedTrack)],
                                'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                                'matched' => (bool) ($deferredAlbumPageTrackMatchedState[spl_object_hash($parsedTrack)] ?? false),
                                'completed' => true,
                            ],
                        ]);

                        continue;
                    }

                    [$track, $trackCreated] = $this->upsertTrackFromGenius($artist, $parsedTrack, $matchedSong['detail']);
                    $this->rememberAlbumPageContextFromTrack($artist, $parsedTrack, $track);

                    if ((int) ($matchedSong['summary']['id'] ?? 0) > 0) {
                        $usedSongIds[] = (int) $matchedSong['summary']['id'];
                    }

                    $wasPreviouslyMatched = (bool) ($deferredAlbumPageTrackMatchedState[spl_object_hash($parsedTrack)] ?? false);

                    if (! $wasPreviouslyMatched) {
                        $matchedTracks++;
                        $unmatchedTracks = max(0, $unmatchedTracks - 1);
                    }

                    $tracksCreated += $trackCreated ? 1 : 0;
                    $this->reportProgress([
                        'tracks_total' => count($page->tracks),
                        'tracks_processed' => count($page->tracks),
                        'matched_tracks' => $matchedTracks,
                        'unmatched_tracks' => $unmatchedTracks,
                        'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                        '_track_checkpoint' => [
                            'source_key' => $pageTrackKeys[spl_object_hash($parsedTrack)],
                            'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                            'matched' => true,
                            'completed' => true,
                        ],
                    ]);
                } catch (Throwable $exception) {
                    $this->geniusUnavailable = $this->geniusUnavailable || $this->isGeniusRequestFailure($exception);
                    $this->matchingLog('warning', 'Genius sync retry skipped one deferred album-page track because of an exception.', [
                        'artist' => $page->artistName,
                        'muzofond_track_title' => GeniusNameMatcher::forceUtf8($parsedTrack->title),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->reconcileCanonicalAlbumTrackMemberships($artist);
        $this->cleanupEmptyAlbumsForArtist($artist);

        $this->reportProgress([
            'tracks_total' => count($page->tracks),
            'tracks_processed' => count($page->tracks),
            'matched_tracks' => $matchedTracks,
            'unmatched_tracks' => $unmatchedTracks,
            'current_track' => null,
        ]);

        return [
            'artist' => $artist,
            'artists' => $artistsCreated,
            'albums' => $albumsCreated,
            'tracks' => $tracksCreated,
            'matched_tracks' => $matchedTracks,
            'unmatched_tracks' => $unmatchedTracks,
            'genius_matched' => true,
                'genius_unavailable' => $this->geniusUnavailable,
        ];
    }

    private function persistMuzofondFallbackTracks(Artist $artist, ParsedArtistPage $page): int
    {
        $this->primeAlbumPageAlbumContext($artist, $page);
        $createdCount = 0;
        $processed = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;

        foreach ($page->tracks as $parsedTrack) {
            $checkpointKey = $this->parsedTrackCheckpointKey($parsedTrack);
            if (! array_key_exists($checkpointKey, $this->completedTrackOutcomes)) {
                continue;
            }

            $processed++;
            $this->completedTrackOutcomes[$checkpointKey] ? $matchedCount++ : $unmatchedCount++;
        }

        foreach ($page->tracks as $parsedTrack) {
            $checkpointKey = $this->parsedTrackCheckpointKey($parsedTrack);

            if (array_key_exists($checkpointKey, $this->completedTrackOutcomes)) {
                continue;
            }

            $albumId = $this->sourceAudioVersionAmbiguous($parsedTrack)
                ? null
                : $this->resolveFallbackAlbumId($artist, $parsedTrack);

            if (! $this->sourceAudioVersionAmbiguous($parsedTrack)
                && $this->hasExistingGeniusTrackForFallback($parsedTrack)) {
                $matchedCount++;
                $processed++;
                $this->reportProgress([
                    'tracks_total' => count($page->tracks),
                    'tracks_processed' => $processed,
                    'matched_tracks' => $matchedCount,
                    'unmatched_tracks' => $unmatchedCount,
                    'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                    '_track_checkpoint' => [
                        'source_key' => $checkpointKey,
                        'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                        'matched' => true,
                        'completed' => true,
                    ],
                ]);

                continue;
            }

            $creditedArtists = $this->resolveFallbackCreditedArtists($page, $parsedTrack, $artist);
            [$track, $trackCreated] = $this->upsertTrackFallback($artist, $albumId, $parsedTrack);
            $track->artists()->syncWithoutDetaching(collect($creditedArtists)->pluck('id')->unique()->values()->all());
            $createdCount += $trackCreated ? 1 : 0;
            $processed++;
            $unmatchedCount++;
            $this->reportProgress([
                'tracks_total' => count($page->tracks),
                'tracks_processed' => $processed,
                'matched_tracks' => $matchedCount,
                'unmatched_tracks' => $unmatchedCount,
                'current_track' => GeniusNameMatcher::storageValue($parsedTrack->title),
                '_track_checkpoint' => [
                    'source_key' => $checkpointKey,
                    'track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                    'matched' => false,
                    'completed' => true,
                ],
            ]);
        }

        $this->cleanupEmptyAlbumsForArtist($artist);

        return $createdCount;
    }

    private function hasExistingGeniusTrackForFallback(ParsedTrack $parsedTrack): bool
    {
        $track = $this->findTrackByAudioIdentity($parsedTrack);

        return $track instanceof Track
            && (int) ($track->genius_id ?? 0) > 0
            && $this->localGeniusTrackMatchesParsedTrack($track->loadMissing('album'), $parsedTrack);
    }

    private function isGeniusRequestFailure(Throwable $exception): bool
    {
        return preg_match('/Genius API вернул \d+|cURL error|Connection timed out|SSL connect error|Could not resolve host/i', $exception->getMessage()) === 1;
    }

    private function primeAlbumPageAlbumContext(Artist $artist, ParsedArtistPage $page): void
    {
        foreach ($page->tracks as $parsedTrack) {
            if ($this->sourceAudioVersionAmbiguous($parsedTrack)) {
                continue;
            }

            if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
                continue;
            }

            $albumId = $this->resolveFallbackAlbumId($artist, $parsedTrack);

            if ($albumId === null) {
                continue;
            }

            $contextKey = $this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle);

            if (($this->albumPageAlbumContextTrusted[$contextKey] ?? false) === true) {
                continue;
            }

            $this->albumPageAlbumContext[$contextKey] = $albumId;
            $this->albumPageAlbumContextTrusted[$contextKey] = false;
        }
    }

    private function rememberAlbumPageContextFromTrack(Artist $artist, ParsedTrack $parsedTrack, ?Track $track): void
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            || ! $track instanceof Track
            || (int) ($track->album_id ?? 0) <= 0) {
            return;
        }

        $contextKey = $this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle);
        $this->albumPageAlbumContext[$contextKey] = (int) $track->album_id;
        $this->albumPageAlbumContextTrusted[$contextKey] = true;
    }

    private function shouldRetryAlbumPageTrackMatch(ParsedTrack $parsedTrack): bool
    {
        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && ! in_array('ringtone', $this->parsedTrackVersionFlags($parsedTrack), true);
    }

    private function shouldRetryMatchedAlbumPageTrackAfterContextResolution(ParsedTrack $parsedTrack, ?Track $track): bool
    {
        if (! $this->shouldRetryAlbumPageTrackMatch($parsedTrack)
            || ! $track instanceof Track
            || (int) ($track->album_id ?? 0) <= 0) {
            return false;
        }

        $album = Album::query()->find((int) $track->album_id);

        if (! $album instanceof Album) {
            return false;
        }

        return ! $this->albumTitlesShareIdentity(
            $this->storedAlbumTitle((string) ($album->title ?? '')),
            $this->storedAlbumTitle((string) $parsedTrack->albumTitle),
        );
    }

    private function parsedTrackVersionContext(ParsedTrack $parsedTrack): string
    {
        return trim(implode(' ', array_filter([
            GeniusNameMatcher::storageValue((string) $parsedTrack->title),
            $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                ? GeniusNameMatcher::storageValue((string) $parsedTrack->albumTitle)
                : '',
        ])));
    }

    /**
     * @return string[]
     */
    private function parsedTrackVersionFlags(ParsedTrack $parsedTrack): array
    {
        return $this->versionFlags($this->parsedTrackVersionContext($parsedTrack));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function strongSummaryFallbackCandidates(array $candidates): array
    {
        $candidates = collect($candidates)
            ->filter(function (array $candidate): bool {
                return (bool) ($candidate['exact_title'] ?? false)
                    && (float) ($candidate['title_priority'] ?? 0.0) >= 0.94
                    && (float) ($candidate['score'] ?? 0.0) >= 0.88;
            })
            ->values()
            ->all();

        usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));

        return array_slice($candidates, 0, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  string[]  $artistReferenceNames
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function resolveStrongSummaryFallbackMatch(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $candidates,
        array $artistReferenceNames,
    ): ?array {
        foreach ($this->strongSummaryFallbackCandidates($candidates) as $candidate) {
            $songSummary = is_array($candidate['song'] ?? null) ? $candidate['song'] : null;

            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            $songDetail = $this->songDetail((int) $songSummary['id']);

            if (! is_array($songDetail)) {
                $songDetail = $songSummary;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);

            $summaryMatchesArtist = $this->songContainsArtist($songSummary, $artistReferenceNames);
            $detailMatchesArtist = $this->songMatchesArtistPage($songDetail, $page);
            $summaryMatchesTitle = $this->songTitleMatchesParsedTrack($parsedTrack, $songSummary);
            $detailMatchesTitle = $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail);

            if (($detailMatchesArtist || $summaryMatchesArtist)
                && $detailMatchesTitle
                && ! $this->sourceAlbumVersionConflictsWithSong($parsedTrack, $songDetail)) {
                return [
                    'summary' => $songSummary,
                    'detail' => $songDetail,
                ];
            }

            if (($detailMatchesArtist || $summaryMatchesArtist)
                && $summaryMatchesTitle
                && ! $this->sourceAlbumVersionConflictsWithSong($parsedTrack, $songDetail)) {
                return [
                    'summary' => $songSummary,
                    'detail' => $songDetail,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     */
    private function primeSongSummaryIndexes(array $songSummaries): void
    {
        $this->currentSongExactTitleIndex = [];
        $this->currentSongLooseTitleIndex = [];

        foreach ($songSummaries as $songSummary) {
            if (! is_array($songSummary)) {
                continue;
            }

            $title = (string) ($songSummary['title'] ?? '');
            $canonicalTitle = GeniusNameMatcher::canonicalTrack($title);
            $looseTitle = GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($title));

            if ($canonicalTitle !== '') {
                $this->currentSongExactTitleIndex[$canonicalTitle][] = $songSummary;
            }

            if ($looseTitle !== '') {
                $this->currentSongLooseTitleIndex[$looseTitle][] = $songSummary;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @return array<int, array<string, mixed>>
     */
    private function songCatalogCandidatesForTrack(ParsedTrack $parsedTrack, array $songSummaries): array
    {
        if (count($songSummaries) < 120 || $this->currentSongExactTitleIndex === []) {
            return $songSummaries;
        }

        $canonicalTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $looseTitle = GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($parsedTrack->title));
        $candidates = [];

        if ($canonicalTitle !== '' && isset($this->currentSongExactTitleIndex[$canonicalTitle])) {
            $candidates = $this->mergeSongSummaries($candidates, $this->currentSongExactTitleIndex[$canonicalTitle]);
        }

        if ($looseTitle !== '' && isset($this->currentSongLooseTitleIndex[$looseTitle])) {
            $candidates = $this->mergeSongSummaries($candidates, $this->currentSongLooseTitleIndex[$looseTitle]);
        }

        return $candidates !== [] ? $candidates : $songSummaries;
    }

    /**
     * Fetch each track's top Genius candidate before matching so detail requests run concurrently.
     * Remaining candidates still use the regular lazy lookup when the top candidate is ambiguous.
     *
     * @param  ParsedTrack[]  $parsedTracks
     * @param  array<int, array<string, mixed>>  $songSummaries
     */
    private function prefetchSongDetailsForTracks(ParsedArtistPage $page, array $parsedTracks, array $songSummaries): void
    {
        if ($songSummaries === [] || $parsedTracks === []) {
            return;
        }

        $songIds = [];

        foreach ($parsedTracks as $parsedTrack) {
            $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);

            if ($parsedTitle === '') {
                continue;
            }

            $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
                : null;
            $candidates = $this->buildSongMatchCandidates(
                $page,
                $parsedTrack,
                $this->songCatalogCandidatesForTrack($parsedTrack, $songSummaries),
                [],
                $parsedTitle,
                $parsedAlbumTitle,
            );

            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
            $candidates = $this->trimSongMatchCandidates($parsedTrack, $candidates);
            $songId = (int) data_get($candidates[0] ?? [], 'song.id', 0);

            if ($songId > 0) {
                $songIds[$songId] = $songId;
            }
        }

        if ($songIds !== []) {
            $this->songDetails = array_replace(
                $this->songDetails,
                $this->geniusClient->songDetails(array_values($songIds)),
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatch(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        array $usedSongIds = [],
        bool $allowSearchFallback = true,
        array $albumSummaries = [],
    ): ?array
    {
        if ($this->sourceAudioVersionAmbiguous($parsedTrack)) {
            $this->matchingLog('info', 'Genius matching skipped an ambiguous Muzofond audio version.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'muzofond_album_title' => $parsedTrack->albumTitle,
                'audio_file_id' => $parsedTrack->audioFileId,
                'source_type' => $parsedTrack->sourceType,
            ]);

            return null;
        }

        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;

        if ($parsedTitle === '') {
            $this->matchingLog('info', 'Genius matching skipped an empty normalized track title.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => $parsedTrack->title,
                'muzofond_album_title' => $parsedTrack->albumTitle,
                'source_type' => $parsedTrack->sourceType,
            ]);

            return null;
        }

        $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        $candidates = $this->buildSongMatchCandidates($page, $parsedTrack, $songSummaries, $usedSongIds, $parsedTitle, $parsedAlbumTitle);

        if ($candidates === []) {
            $albumTrackMatch = $this->findBestSongMatchFromAlbumTracks($page, $parsedTrack, $albumSummaries, $usedSongIds);

            if ($albumTrackMatch !== null) {
                $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], $albumTrackMatch, 'album-tracks-empty-catalog');

                return $albumTrackMatch;
            }

            $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], null, 'catalog-empty');

            return $allowSearchFallback
                ? $this->searchNoCatalogCandidateWithinBudget(
                    $page,
                    $parsedTrack,
                    $usedSongIds,
                    $songSummaries,
                    $albumSummaries,
                )
                : null;
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $candidates = $this->trimSongMatchCandidates($parsedTrack, $candidates);

        $resolvedCandidates = $this->resolveSongMatchCandidates($page, $parsedTrack, $candidates, $parsedTitle, $parsedAlbumTitle);

        if ($this->shouldTryAlbumTrackFallback($parsedTrack, $candidates, $resolvedCandidates, $albumSummaries)) {
            $albumTrackMatch = $this->findBestSongMatchFromAlbumTracks($page, $parsedTrack, $albumSummaries, $usedSongIds);

            if ($albumTrackMatch !== null) {
                $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $albumTrackMatch, 'album-tracks');

                return $albumTrackMatch;
            }
        }

        if ($allowSearchFallback && $this->shouldSearchForBetterSongMatch($parsedAlbumTitle, $candidates, $resolvedCandidates)) {
            $bestSummaryCandidate = $candidates[0] ?? null;
            $bestResolvedCandidate = $resolvedCandidates[0] ?? null;
            $this->matchingLog('info', 'Genius catalog match triggered fallback search.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'muzofond_album_title' => $parsedAlbumTitle,
                'summary_candidate_count' => count($candidates),
                'resolved_candidate_count' => count($resolvedCandidates),
                'best_summary_candidate' => $bestSummaryCandidate ? [
                    'genius_id' => (int) data_get($bestSummaryCandidate, 'song.id', 0),
                    'title' => data_get($bestSummaryCandidate, 'song.title'),
                    'album' => data_get($bestSummaryCandidate, 'song.album.name')
                        ?? data_get($bestSummaryCandidate, 'song.album.title'),
                    'score' => round((float) ($bestSummaryCandidate['score'] ?? 0.0), 4),
                    'title_priority' => round((float) ($bestSummaryCandidate['title_priority'] ?? 0.0), 4),
                    'album_preference' => (int) ($bestSummaryCandidate['album_preference'] ?? 0),
                ] : null,
                'best_resolved_candidate' => $bestResolvedCandidate ? [
                    'genius_id' => (int) data_get($bestResolvedCandidate, 'summary.id', 0),
                    'title' => data_get($bestResolvedCandidate, 'detail.title'),
                    'album' => data_get($bestResolvedCandidate, 'detail.album.name')
                        ?? data_get($bestResolvedCandidate, 'detail.album.title'),
                    'score' => round((float) ($bestResolvedCandidate['score'] ?? 0.0), 4),
                    'title_priority' => round((float) ($bestResolvedCandidate['title_priority'] ?? 0.0), 4),
                    'album_preference' => (int) ($bestResolvedCandidate['album_preference'] ?? 0),
                ] : null,
            ]);

            $searchResults = $this->searchSongSummaries(
                $page,
                $parsedTrack,
                'catalog_match_not_confident',
                $songSummaries,
                $usedSongIds,
            );

            if ($searchResults !== []) {
                $searchMatch = $this->findBestSongMatch(
                    $page,
                    $parsedTrack,
                    $this->mergeSongSummaries($songSummaries, $searchResults),
                    $usedSongIds,
                    false,
                    $albumSummaries,
                );

                if ($searchMatch !== null) {
                    $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $searchMatch, 'search-merged');

                    return $searchMatch;
                }
            }
        }

        if ($resolvedCandidates === []) {
            $summaryFallbackMatch = $this->resolveStrongSummaryFallbackMatch($page, $parsedTrack, $candidates, $artistReferenceNames);

            if ($summaryFallbackMatch !== null) {
                $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $summaryFallbackMatch, 'catalog-summary-fallback');

                return $summaryFallbackMatch;
            }

            $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, null, 'catalog');

            return null;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate) || ! is_array($bestCandidate['summary'] ?? null) || ! is_array($bestCandidate['detail'] ?? null)) {
            $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, null, 'catalog');

            return null;
        }

        $matchedSong = [
            'summary' => $bestCandidate['summary'],
            'detail' => $bestCandidate['detail'],
        ];

        $this->logSongMatchDiagnostics($page, $parsedTrack, $candidates, $resolvedCandidates, $matchedSong, 'catalog');

        return $matchedSong;
    }

    private function fallbackSearchResolutionKey(ParsedTrack $parsedTrack): string
    {
        return hash('sha256', implode('|', [
            $this->parsedTrackCheckpointKey($parsedTrack),
            GeniusNameMatcher::normalizeLoose((string) ($parsedTrack->albumTitle ?? '')),
            $parsedTrack->sourceType,
        ]));
    }

    /**
     * Run the per-track Genius fallback only while the artist-level miss budget
     * remains. Muzofond persistence happens in the caller regardless of this result.
     *
     * @param int[] $usedSongIds
     * @param array<int, array<string, mixed>> $songSummaries
     * @param array<int, array<string, mixed>> $albumSummaries
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function searchNoCatalogCandidateWithinBudget(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $usedSongIds,
        array $songSummaries,
        array $albumSummaries,
    ): ?array {
        $sourceKey = $this->fallbackSearchResolutionKey($parsedTrack);

        if (array_key_exists($sourceKey, $this->fallbackSearchResultsByTrack)) {
            return $this->fallbackSearchResultsByTrack[$sourceKey];
        }

        if ((bool) ($this->fallbackSamplingState['stopped'] ?? false)) {
            $this->fallbackSamplingState['tracks_skipped'] = (int) ($this->fallbackSamplingState['tracks_skipped'] ?? 0) + 1;
            $this->fallbackSearchResultsByTrack[$sourceKey] = null;
            while (count($this->fallbackSearchResultsByTrack) > 128) {
                array_shift($this->fallbackSearchResultsByTrack);
            }
            $this->matchingLog('info', 'Genius no-catalog fallback skipped after the artist miss limit.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'consecutive_misses' => (int) ($this->fallbackSamplingState['consecutive_misses'] ?? 0),
                'miss_limit' => (int) ($this->fallbackSamplingState['miss_limit'] ?? 10),
                'stop_reason' => $this->fallbackSamplingState['stop_reason'] ?? null,
            ]);

            return null;
        }

        $this->fallbackSamplingState['tracks_attempted'] = (int) ($this->fallbackSamplingState['tracks_attempted'] ?? 0) + 1;

        try {
            $match = $this->findBestSongMatchFromSearch(
                $page,
                $parsedTrack,
                $usedSongIds,
                'catalog_has_no_candidates',
                $songSummaries,
                $albumSummaries,
            );
        } catch (Throwable $exception) {
            $this->fallbackSamplingState['network_failures'] = (int) ($this->fallbackSamplingState['network_failures'] ?? 0) + 1;
            $this->matchingLog('info', 'Genius fallback failure did not count as a no-match.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'consecutive_misses' => (int) ($this->fallbackSamplingState['consecutive_misses'] ?? 0),
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($match !== null) {
            $this->fallbackSamplingState['tracks_matched'] = (int) ($this->fallbackSamplingState['tracks_matched'] ?? 0) + 1;
            $this->fallbackSamplingState['consecutive_misses'] = 0;
            $this->fallbackSamplingState['miss_limit'] = 30;
        } elseif (isset($this->fallbackNetworkFailuresByTrack[$sourceKey])) {
            $this->fallbackSamplingState['network_failures'] = (int) ($this->fallbackSamplingState['network_failures'] ?? 0) + 1;
            $this->matchingLog('info', 'Genius fallback result was unknown after an album-track request failure; it did not count as a miss.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'consecutive_misses' => (int) ($this->fallbackSamplingState['consecutive_misses'] ?? 0),
            ]);
        } else {
            $this->fallbackSamplingState['consecutive_misses'] = (int) ($this->fallbackSamplingState['consecutive_misses'] ?? 0) + 1;
            $missLimit = (int) ($this->fallbackSamplingState['miss_limit'] ?? 10);

            if ((int) $this->fallbackSamplingState['consecutive_misses'] >= $missLimit) {
                $this->fallbackSamplingState['stopped'] = true;
                $this->fallbackSamplingState['stop_reason'] = $missLimit === 10
                    ? 'initial_10_consecutive_misses'
                    : '30_consecutive_misses_after_match';
                $this->matchingLog('info', 'Genius no-catalog fallback stopped for this artist task after consecutive misses.', [
                    'artist' => $page->artistName,
                    'consecutive_misses' => (int) $this->fallbackSamplingState['consecutive_misses'],
                    'miss_limit' => $missLimit,
                    'tracks_attempted' => (int) $this->fallbackSamplingState['tracks_attempted'],
                    'tracks_matched' => (int) $this->fallbackSamplingState['tracks_matched'],
                ]);
            }
        }

        $this->fallbackSearchResultsByTrack[$sourceKey] = $match;
        while (count($this->fallbackSearchResultsByTrack) > 128) {
            array_shift($this->fallbackSearchResultsByTrack);
        }

        return $match;
    }

    /**
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatchFromSearch(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $usedSongIds = [],
        string $triggerReason = 'catalog_has_no_candidates',
        array $baselineSongSummaries = [],
        array $albumSummaries = [],
    ): ?array
    {
        $searchResults = $this->searchSongSummaries($page, $parsedTrack, $triggerReason, $baselineSongSummaries, $usedSongIds);

        if ($searchResults === []) {
            $this->logSongMatchDiagnostics($page, $parsedTrack, [], [], null, 'search-empty');

            return null;
        }

        return $this->findBestSongMatch($page, $parsedTrack, $searchResults, $usedSongIds, false, $albumSummaries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchSongSummaries(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        string $triggerReason,
        array $baselineSongSummaries = [],
        array $usedSongIds = [],
    ): array
    {
        $cacheKey = md5(implode('|', [
            GeniusNameMatcher::storageValue($page->artistName),
            GeniusNameMatcher::storageValue($parsedTrack->title),
            GeniusNameMatcher::storageValue((string) ($parsedTrack->albumTitle ?? '')),
        ]));

        if (! array_key_exists($cacheKey, $this->searchSongSummaryCache)) {
            $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
                ? $this->currentPageArtistReferenceNames
                : $this->pageArtistReferenceNames($page);
            $queries = $this->targetedSongSearchQueries($artistReferenceNames, $parsedTrack);
            $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
            $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
                : null;
            $mergedSummaries = [];
            $maxQueries = $this->searchQueryAttemptLimit();
            $queryBatch = array_slice($queries, 0, $maxQueries);
            $baselineSongIds = collect($baselineSongSummaries)
                ->map(fn (array $summary): int => (int) ($summary['id'] ?? 0))
                ->filter(fn (int $songId): bool => $songId > 0)
                ->unique()
                ->values()
                ->all();
            $queriesUsed = 0;
            $previousCandidateState = null;
            $stableCandidateQueries = 0;
            $stopReason = $queryBatch === [] ? 'no_queries_generated' : 'query_limit_reached';

            $this->matchingLog('info', 'Genius fallback search sequence started.', [
                'artist' => $page->artistName,
                'artist_slug' => $page->artistSlug,
                'genius_artist_id' => $this->currentArtistGeniusId,
                'muzofond_track' => [
                    'title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                    'album' => $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                        ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
                        : null,
                    'track_number' => $parsedTrack->trackNumber,
                    'release_year' => $parsedTrack->releaseYear,
                    'source_type' => $parsedTrack->sourceType,
                ],
                'trigger_reason' => $triggerReason,
                'unique_queries_generated' => count($queries),
                'query_limit' => $maxQueries,
                'queries_scheduled' => count($queryBatch),
                'stop_after_no_candidates' => min(1, count($queryBatch)),
                'artist_references' => $artistReferenceNames,
            ]);

            $queriesEvaluated = 0;
            $queriesDispatched = 0;

            foreach ($queryBatch as $queryIndex => $query) {
                $startedAt = hrtime(true);
                $metricsBefore = $this->geniusClient->metrics();
                $requestsBefore = (int) ($metricsBefore['groups']['song_search']['requests'] ?? 0);
                $summaryIdsBefore = array_fill_keys(array_map(
                    static fn (array $summary): int => (int) ($summary['id'] ?? 0),
                    $mergedSummaries,
                ), true);

                try {
                    $hits = collect($this->geniusClient->searchSongs($query))
                        ->filter(fn ($song) => is_array($song))
                        ->values()
                        ->all();
                } catch (Throwable $exception) {
                    $this->matchingLog('warning', 'Genius fallback search query failed.', [
                        'artist' => $page->artistName,
                        'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                        'trigger_reason' => $triggerReason,
                        'query_number' => $queryIndex + 1,
                        'query_limit' => count($queryBatch),
                        'query' => $query,
                        'elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                        'exception' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }
                $queriesEvaluated++;
                $queriesDispatched += (int) (($this->geniusClient->metrics()['groups']['song_search']['requests'] ?? 0) > $requestsBefore);

                $mergedSummaries = $this->mergeSongSummaries($mergedSummaries, $hits);
                $queriesUsed++;
                $decisiveDiagnostics = [];
                $decisive = $this->searchSummaryPoolIsDecisive(
                    $page,
                    $parsedTrack,
                    $mergedSummaries,
                    $parsedTitle,
                    $parsedAlbumTitle,
                    $decisiveDiagnostics,
                    $queriesUsed,
                    $baselineSongSummaries,
                    $baselineSongIds,
                    $artistReferenceNames,
                    $usedSongIds,
                );
                $candidateState = $this->fallbackCandidateState($decisiveDiagnostics);

                if ((int) ($decisiveDiagnostics['candidate_count'] ?? 0) > 0) {
                    if ($candidateState === $previousCandidateState) {
                        $stableCandidateQueries++;
                    } else {
                        $stableCandidateQueries = 0;
                    }

                    $previousCandidateState = $candidateState;
                } else {
                    $previousCandidateState = null;
                    $stableCandidateQueries = 0;
                }

                $newHits = collect($hits)
                    ->reject(fn (array $hit): bool => isset($summaryIdsBefore[(int) ($hit['id'] ?? 0)]))
                    ->values();

                $this->matchingLog('info', 'Genius fallback search query completed.', [
                    'artist' => $page->artistName,
                    'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                    'muzofond_album_title' => $parsedTrack->albumTitle,
                    'trigger_reason' => $triggerReason,
                    'query_number' => $queryIndex + 1,
                    'query_limit' => count($queryBatch),
                    'query' => $query,
                    'network_request' => (int) (($this->geniusClient->metrics()['groups']['song_search']['requests'] ?? 0) > $requestsBefore),
                    'elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                    'returned_hits' => count($hits),
                    'new_song_ids' => $newHits->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'matched_pool_size' => count($mergedSummaries),
                    'decisive_after_this_query' => $decisive,
                    'stable_candidate_queries' => $stableCandidateQueries,
                    'decisive_check' => $decisiveDiagnostics,
                    'hits' => $newHits
                        ->take(8)
                        ->map(fn (array $hit): array => $this->searchHitDiagnosticPayload($hit))
                        ->values()
                        ->all(),
                ]);

                if ($decisive) {
                    $stopReason = 'decisive_candidate_found';
                    break;
                }

                // Historical fallback logs show no cases where query 2 produced a
                // plausible candidate after the direct artist + source-title query
                // produced none. Keep alternate searches when query 1 found candidates:
                // they can still add album/transliteration evidence or resolve ambiguity.
                if ($queriesUsed >= 1
                    && (int) ($decisiveDiagnostics['candidate_count'] ?? 0) === 0) {
                    $stopReason = 'no_candidates_after_first_targeted_query';

                    break;
                }

                if ($triggerReason === 'catalog_match_not_confident'
                    && $queriesUsed === 2
                    && count($queryBatch) > 2
                    && $stableCandidateQueries >= 1) {
                    if (($decisiveDiagnostics['stop_reason'] ?? null) === 'waiting_for_three_distinct_queries') {
                        $this->fallbackSamplingState['third_query_preserved_waiting'] = (int) ($this->fallbackSamplingState['third_query_preserved_waiting'] ?? 0) + 1;
                    } else {
                        $stopReason = 'third_query_skipped_stable_candidate_pool';
                        $this->fallbackSamplingState['third_query_skipped'] = (int) ($this->fallbackSamplingState['third_query_skipped'] ?? 0) + 1;
                        $this->fallbackSamplingState['third_query_skip_reason'] = 'candidate_pool_unchanged_after_query_2_and_not_waiting_for_three_distinct_queries';
                        $this->matchingLog('info', 'Genius uncertain-match fallback skipped query 3 because query 2 did not change the candidate pool.', [
                            'artist' => $page->artistName,
                            'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                            'query_1_and_2_candidate_pool_unchanged' => true,
                            'candidate_count' => (int) ($decisiveDiagnostics['candidate_count'] ?? 0),
                            'q2_stop_reason' => $decisiveDiagnostics['stop_reason'] ?? null,
                        ]);

                        break;
                    }
                }
            }

            $this->searchSongSummaryCache[$cacheKey] = $mergedSummaries;
            while (count($this->searchSongSummaryCache) > 128) {
                array_shift($this->searchSongSummaryCache);
            }
            $this->matchingLog('info', 'Genius fallback search sequence completed.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'muzofond_album_title' => $parsedTrack->albumTitle,
                'trigger_reason' => $triggerReason,
                'generated_query_count' => count($queries),
                'query_limit' => $maxQueries,
                'queries_used' => $queriesUsed,
                'queries_evaluated' => $queriesEvaluated,
                'network_search_requests' => $queriesDispatched,
                'queries_saved_by_runtime_cache' => max(0, $queriesEvaluated - $queriesDispatched),
                'candidate_song_count' => count($mergedSummaries),
                'candidate_song_ids' => collect($mergedSummaries)->take(30)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'stop_reason' => $stopReason,
            ]);
        } else {
            $this->matchingLog('info', 'Genius fallback search results reused within the current artist run.', [
                'artist' => $page->artistName,
                'muzofond_track_title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'muzofond_album_title' => $parsedTrack->albumTitle,
                'search_summary_cache_key' => $cacheKey,
                'cached_candidate_count' => count($this->searchSongSummaryCache[$cacheKey]),
                'cached_candidate_ids' => collect($this->searchSongSummaryCache[$cacheKey])->take(30)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ]);
        }

        return $this->searchSongSummaryCache[$cacheKey];
    }

    /**
     * Build at most three materially different Genius search queries.
     *
     * @param  string[]  $artistReferenceNames
     * @return string[]
     */
    private function targetedSongSearchQueries(array $artistReferenceNames, ParsedTrack $parsedTrack): array
    {
        $artistNames = collect($artistReferenceNames)
            ->map(fn (string $name): string => trim(GeniusNameMatcher::storageValue($name)))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name, 'UTF-8'))
            ->values();
        $primaryArtistName = (string) ($artistNames->first() ?? '');
        $title = trim(GeniusNameMatcher::storageValue(GeniusNameMatcher::forceUtf8((string) $parsedTrack->title)));

        if ($primaryArtistName === '' || $title === '') {
            return [];
        }

        $queries = [$primaryArtistName . ' ' . $title];
        $album = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? trim($this->storedAlbumTitle((string) $parsedTrack->albumTitle))
            : '';
        $albumIsMeaningfullyDifferent = $album !== ''
            && GeniusNameMatcher::canonicalTrack($album) !== GeniusNameMatcher::canonicalTrack($title);

        if ($albumIsMeaningfullyDifferent) {
            $queries[] = $primaryArtistName . ' ' . $title . ' ' . $album;
        }

        // Reuse the existing transliteration/normalization generator, but take only its
        // first distinct form. Never generate album permutations when album == track title.
        $alternateQueries = GeniusNameMatcher::songSearchQueries(
            $primaryArtistName,
            $parsedTrack->title,
            $albumIsMeaningfullyDifferent ? $album : null,
        );

        foreach ($alternateQueries as $query) {
            $query = trim((string) (preg_replace('/\s+/u', ' ', $query) ?? $query));

            if ($query === '' || $this->sameSearchQuery($query, $queries)) {
                continue;
            }

            $queries[] = $query;

            if (count($queries) >= 3) {
                break;
            }
        }

        // If normalization produced no alternate title, try a distinct Genius artist alias.
        foreach ($artistNames->slice(1) as $artistName) {
            $query = $artistName . ' ' . $title;

            if (! $this->sameSearchQuery($query, $queries)) {
                $queries[] = $query;
                break;
            }
        }

        return array_slice($queries, 0, 3);
    }

    /** @param string[] $existingQueries */
    private function sameSearchQuery(string $query, array $existingQueries): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower(
            trim((string) (preg_replace('/\s+/u', ' ', $value) ?? $value)),
            'UTF-8',
        );
        $normalized = $normalize($query);

        foreach ($existingQueries as $existingQuery) {
            if ($normalized === $normalize((string) $existingQuery)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function fallbackCandidateState(array $diagnostics): string
    {
        $candidateIds = collect((array) ($diagnostics['candidate_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'candidate_ids' => $candidateIds,
            'candidate_signatures' => $diagnostics['candidate_signatures'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function searchQueryAttemptLimit(): int
    {
        return 3;
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     */
    private function searchSummaryPoolIsDecisive(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
        ?array &$diagnostics = null,
        int $queriesUsed = 0,
        array $baselineSongSummaries = [],
        array $baselineSongIds = [],
        array $artistReferenceNames = [],
        array $usedSongIds = [],
    ): bool {
        $candidateSummaries = $this->mergeSongSummaries($baselineSongSummaries, $songSummaries);
        $candidates = $this->buildSongMatchCandidates($page, $parsedTrack, $candidateSummaries, $usedSongIds, $parsedTitle, $parsedAlbumTitle);
        $diagnostics = [
            'candidate_count' => count($candidates),
            'candidate_ids' => collect($candidates)
                ->map(fn (array $candidate): int => (int) data_get($candidate, 'song.id', 0))
                ->filter(fn (int $songId): bool => $songId > 0)
                ->unique()
                ->values()
                ->all(),
        ];

        if ($candidates === []) {
            $diagnostics['stop_reason'] = 'no_match_candidates';

            return false;
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $candidates[0] ?? null;
        $diagnostics['candidate_signatures'] = collect($candidates)
            ->map(function (array $candidate): array {
                $song = is_array($candidate['song'] ?? null) ? $candidate['song'] : [];

                return [
                    'genius_id' => (int) data_get($song, 'id', 0),
                    'title' => GeniusNameMatcher::storageValue((string) data_get($song, 'title', '')),
                    'album' => $this->extractSongAlbumTitle($song),
                    'release_date' => data_get($song, 'release_date'),
                    'track_number' => $this->directTrackNumberFromPayload($song),
                    'score' => round((float) ($candidate['score'] ?? 0.0), 4),
                    'title_priority' => round((float) ($candidate['title_priority'] ?? 0.0), 4),
                    'album_preference' => (int) ($candidate['album_preference'] ?? 0),
                    'used' => (bool) ($candidate['used'] ?? false),
                    'non_original_album' => (bool) ($candidate['non_original_album'] ?? false),
                ];
            })
            ->sortBy('genius_id')
            ->values()
            ->all();

        if (! is_array($bestCandidate)) {
            $diagnostics['stop_reason'] = 'no_ranked_candidate';

            return false;
        }

        $diagnostics['best_candidate'] = [
            'genius_id' => (int) data_get($bestCandidate, 'song.id', 0),
            'title' => (string) data_get($bestCandidate, 'song.title', ''),
            'album' => data_get($bestCandidate, 'song.album.name')
                ?? data_get($bestCandidate, 'song.album.title'),
            'score' => round((float) ($bestCandidate['score'] ?? 0.0), 4),
            'title_priority' => round((float) ($bestCandidate['title_priority'] ?? 0.0), 4),
            'album_preference' => (int) ($bestCandidate['album_preference'] ?? 0),
            'exact_title' => (bool) ($bestCandidate['exact_title'] ?? false),
            'non_original_album' => (bool) ($bestCandidate['non_original_album'] ?? false),
            'unreleased' => (bool) ($bestCandidate['unreleased'] ?? false),
        ];

        if (! (bool) ($bestCandidate['exact_title'] ?? false)) {
            $diagnostics['stop_reason'] = 'best_candidate_title_not_exact';

            return false;
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && ((bool) ($bestCandidate['non_original_album'] ?? false)
                || (bool) ($bestCandidate['unreleased'] ?? false))) {
            $diagnostics['stop_reason'] = 'candidate_conflicts_with_original_album_preference';

            return false;
        }

        if ($parsedAlbumTitle !== null && (int) ($bestCandidate['album_preference'] ?? 0) < 2) {
            $bestSongId = (int) data_get($bestCandidate, 'song.id', 0);
            $exactCandidates = collect($candidates)
                ->filter(fn (array $candidate): bool => (bool) ($candidate['exact_title'] ?? false)
                    && ! (bool) ($candidate['used'] ?? false)
                    && (float) ($candidate['title_priority'] ?? 0.0) >= 0.94)
                ->unique(fn (array $candidate): int => (int) data_get($candidate, 'song.id', 0))
                ->values();

            if ($exactCandidates->count() !== 1 || ! in_array($bestSongId, $baselineSongIds, true)) {
                $diagnostics['stop_reason'] = $exactCandidates->count() !== 1
                    ? 'exact_candidate_is_ambiguous'
                    : 'exact_candidate_not_in_initial_catalog';

                return false;
            }

            $songSummary = $bestCandidate['song'] ?? [];
            $songDetail = $this->songDetail($bestSongId);

            if (! is_array($songDetail)) {
                $diagnostics['stop_reason'] = 'candidate_detail_unavailable';

                return false;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);
            $candidateAlbumTitle = $this->extractSongAlbumTitle($songDetail);
            $detailAlbumScore = $this->albumMatchAdjustment(
                $parsedAlbumTitle,
                $candidateAlbumTitle,
                $parsedTrack->title,
                (string) ($songDetail['title'] ?? ''),
            );
            $detailAlbumPreference = $this->albumPreferenceValue($parsedAlbumTitle, $candidateAlbumTitle, $detailAlbumScore);
            $explicitAlbumMatch = $candidateAlbumTitle !== null && $detailAlbumPreference >= 2;

            if (! $explicitAlbumMatch) {
                $explicitAlbumMatch = collect((array) ($songDetail['albums'] ?? []))
                    ->filter(fn ($album): bool => is_array($album))
                    ->contains(function (array $album) use ($parsedAlbumTitle, $parsedTrack, $songDetail): bool {
                        $albumTitle = $this->extractAlbumPayloadTitle($album);

                        return $albumTitle !== null
                            && $this->albumMatchAdjustment(
                                $parsedAlbumTitle,
                                $albumTitle,
                                $parsedTrack->title,
                                (string) ($songDetail['title'] ?? ''),
                            ) >= 0.12;
                    });
            }

            $detailMatchesArtist = $this->songMatchesArtistPage($songDetail, $page)
                || $this->songContainsArtist($songDetail, $artistReferenceNames);
            $detailMatchesTitle = $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail);
            $detailMatchesVersion = $this->songAlbumMatchesParsedTrack($parsedTrack, $songDetail);
            $detailConflictsWithOriginal = $this->parsedTrackPrefersOriginalAlbum($parsedTrack)
                && (($candidateAlbumTitle !== null && $this->hasNonOriginalAlbumMarkers($candidateAlbumTitle))
                    || $this->hasNonOriginalAlbumMarkers((string) ($songDetail['title'] ?? ''))
                    || ($candidateAlbumTitle !== null && $this->hasUnreleasedMarkers($candidateAlbumTitle))
                    || $this->hasUnreleasedMarkers((string) ($songDetail['title'] ?? '')));

            $diagnostics['candidate_detail'] = [
                'genius_id' => $bestSongId,
                'title' => (string) ($songDetail['title'] ?? ''),
                'album' => $candidateAlbumTitle,
                'matches_artist' => $detailMatchesArtist,
                'matches_title' => $detailMatchesTitle,
                'matches_version' => $detailMatchesVersion,
                'explicit_album_match' => $explicitAlbumMatch,
            ];

            if (! $explicitAlbumMatch && $candidateAlbumTitle === null && $queriesUsed < 3) {
                $diagnostics['stop_reason'] = 'waiting_for_three_distinct_queries';

                return false;
            }

            if (! $detailMatchesArtist || ! $detailMatchesTitle || ! $detailMatchesVersion || $detailConflictsWithOriginal) {
                $diagnostics['stop_reason'] = 'candidate_detail_did_not_confirm_match';

                return false;
            }

            if ($candidateAlbumTitle !== null && ! $explicitAlbumMatch) {
                $diagnostics['stop_reason'] = 'candidate_detail_album_conflict';

                return false;
            }

            if (! $explicitAlbumMatch && (array) ($songDetail['albums'] ?? []) !== []) {
                $diagnostics['stop_reason'] = 'candidate_detail_albums_do_not_confirm_source';

                return false;
            }

            $diagnostics['stop_reason'] = 'unique_catalog_candidate_confirmed_by_detail';

            return true;
        }

        $decisive = round((float) ($bestCandidate['title_priority'] ?? 0.0), 4) >= 0.94;
        $diagnostics['stop_reason'] = $decisive ? 'strong_candidate_found' : 'title_priority_below_threshold';

        return $decisive;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     * @param  array<int, array<string, mixed>>  $albumSummaries
     */
    private function shouldTryAlbumTrackFallback(
        ParsedTrack $parsedTrack,
        array $summaryCandidates,
        array $resolvedCandidates,
        array $albumSummaries,
    ): bool
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle) || $albumSummaries === []) {
            return false;
        }

        if ($resolvedCandidates === []) {
            usort($summaryCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
            $bestSummaryCandidate = $summaryCandidates[0] ?? null;

            if (is_array($bestSummaryCandidate)
                && (bool) ($bestSummaryCandidate['exact_title'] ?? false)
                && (bool) ($bestSummaryCandidate['album_present'] ?? false)
                && ! (bool) ($bestSummaryCandidate['non_original_album'] ?? false)
                && (float) ($bestSummaryCandidate['title_priority'] ?? 0.0) >= 0.97) {
                return false;
            }

            return true;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate)) {
            return true;
        }

        if ((bool) ($bestCandidate['exact_title'] ?? false)
            && (bool) ($bestCandidate['album_present'] ?? false)
            && ! (bool) ($bestCandidate['non_original_album'] ?? false)
            && $this->parsedTrackUsesTrackNumberSignal($parsedTrack)) {
            return false;
        }

        if (count($resolvedCandidates) === 1
            && (bool) ($bestCandidate['exact_title'] ?? false)
            && (bool) ($bestCandidate['album_present'] ?? false)
            && ! (bool) ($bestCandidate['non_original_album'] ?? false)
            && (float) ($bestCandidate['title_priority'] ?? 0.0) >= 0.97) {
            return false;
        }

        $hasStrongTrackNumberSupport = (float) ($bestCandidate['track_number_score'] ?? 0.0) >= 0.18
            && (int) ($bestCandidate['album_preference'] ?? 0) >= 1;
        $hasStrongAlbumSupport = (int) ($bestCandidate['album_preference'] ?? 0) >= 2
            && (float) ($bestCandidate['release_year_score'] ?? 0.0) >= 0.12;

        if ((bool) ($bestCandidate['album_present'] ?? false)
            && (float) ($bestCandidate['title_priority'] ?? 0.0) >= 0.97
            && ! (bool) ($bestCandidate['non_original_album'] ?? false)
            && ($hasStrongTrackNumberSupport || $hasStrongAlbumSupport)
            && ($parsedTrack->releaseYear === null || (float) ($bestCandidate['release_year_score'] ?? 0.0) >= 0.0)) {
            return false;
        }

        if ((int) ($bestCandidate['album_preference'] ?? 0) < 3) {
            return true;
        }

        if ((bool) ($bestCandidate['non_original_album'] ?? false) && $this->parsedTrackVersionFlags($parsedTrack) === []) {
            return true;
        }

        return $parsedTrack->releaseYear !== null && (float) ($bestCandidate['release_year_score'] ?? 0.0) < 0.12;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @param  int[]  $usedSongIds
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findBestSongMatchFromAlbumTracks(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $albumSummaries,
        array $usedSongIds = [],
    ): ?array {
        $albumCandidates = $this->buildAlbumTrackFallbackCandidates($parsedTrack, $albumSummaries);

        if ($albumCandidates === []) {
            $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, [], [], null);

            return null;
        }

        $albumTrackSongs = [];
        $inspectedAlbums = [];

        foreach (array_slice($albumCandidates, 0, $this->albumTrackFallbackInspectionLimit($albumCandidates)) as $albumCandidate) {
            $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            $songs = $this->albumTrackSongs($albumId, $albumPayload, $this->fallbackSearchResolutionKey($parsedTrack));
            $inspectedAlbums[] = [
                'album' => $albumCandidate,
                'tracks' => count($songs),
            ];

            if ($songs === []) {
                continue;
            }

            $albumTrackSongs = $this->mergeSongSummaries($albumTrackSongs, $songs);

            if (count($albumTrackSongs) >= 60) {
                break;
            }
        }

        if ($albumTrackSongs === []) {
            $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, $albumCandidates, $inspectedAlbums, null);

            return null;
        }

        $match = $this->findBestSongMatch($page, $parsedTrack, $albumTrackSongs, $usedSongIds, false);

        if ($match === null) {
            $match = $this->findCompactAlbumTrackFallbackMatch($page, $parsedTrack, $albumTrackSongs);
        }

        $this->logAlbumTrackFallbackDiagnostics($page, $parsedTrack, $albumCandidates, $inspectedAlbums, $match);

        return $match;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumTrackSongs
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}|null
     */
    private function findCompactAlbumTrackFallbackMatch(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $albumTrackSongs,
    ): ?array {
        $parsedCompactTitle = $this->compactSongTitle((string) $parsedTrack->title);

        if ($parsedCompactTitle === '') {
            return null;
        }

        $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        $matches = [];

        foreach ($albumTrackSongs as $songSummary) {
            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            if ($this->compactSongTitle((string) ($songSummary['title'] ?? '')) !== $parsedCompactTitle) {
                continue;
            }

            $songDetail = $this->songDetail((int) ($songSummary['id'] ?? 0));

            if (! is_array($songDetail)) {
                $songDetail = $songSummary;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);

            $summaryMatchesArtist = $this->songContainsArtist($songSummary, $artistReferenceNames);
            $detailMatchesArtist = $this->songMatchesArtistPage($songDetail, $page);

            if (! $summaryMatchesArtist && ! $detailMatchesArtist) {
                continue;
            }

            $titleRaw = (string) ($songDetail['title'] ?? $songSummary['title'] ?? '');
            if ($this->versionMatchAdjustment($this->parsedTrackVersionContext($parsedTrack), $titleRaw) < -0.24) {
                continue;
            }

            $albumTitle = $this->extractSongAlbumTitle($songDetail);
            $matches[] = [
                'summary' => $songSummary,
                'detail' => $songDetail,
                'non_original_album' => ($albumTitle ? $this->hasNonOriginalAlbumMarkers($albumTitle) : false)
                    || $this->hasNonOriginalAlbumMarkers($titleRaw),
                'unreleased' => ($albumTitle ? $this->hasUnreleasedMarkers($albumTitle) : false)
                    || $this->hasUnreleasedMarkers($titleRaw),
                'release_sort' => $this->releaseSortValue($songDetail),
            ];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $left, array $right): int {
            $leftNonOriginal = (bool) ($left['non_original_album'] ?? false);
            $rightNonOriginal = (bool) ($right['non_original_album'] ?? false);

            if ($leftNonOriginal !== $rightNonOriginal) {
                return $leftNonOriginal <=> $rightNonOriginal;
            }

            $leftUnreleased = (bool) ($left['unreleased'] ?? false);
            $rightUnreleased = (bool) ($right['unreleased'] ?? false);

            if ($leftUnreleased !== $rightUnreleased) {
                return $leftUnreleased <=> $rightUnreleased;
            }

            return (int) ($left['release_sort'] ?? PHP_INT_MAX) <=> (int) ($right['release_sort'] ?? PHP_INT_MAX);
        });

        $winner = $matches[0] ?? null;

        if (! is_array($winner)
            || ! is_array($winner['summary'] ?? null)
            || ! is_array($winner['detail'] ?? null)) {
            return null;
        }

        return [
            'summary' => $winner['summary'],
            'detail' => $winner['detail'],
        ];
    }

    private function compactSongTitle(string $value): string
    {
        $normalized = GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($value));
        $normalized = preg_replace('/[\s\p{P}\p{S}]+/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     * @return array<int, array<string, mixed>>
     */
    private function buildAlbumTrackFallbackCandidates(ParsedTrack $parsedTrack, array $albumSummaries): array
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return [];
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);
        $parsedAlbumComparisonTitle = $this->normalizeAlbumComparisonTitle($parsedAlbumTitle);
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $candidates = [];

        foreach ($albumSummaries as $albumSummary) {
            if (! is_array($albumSummary) || empty($albumSummary['id'])) {
                continue;
            }

            $albumTitle = $this->storedAlbumTitle((string) ($albumSummary['name'] ?? $albumSummary['title'] ?? ''));

            if ($albumTitle === '') {
                continue;
            }

            $albumSimilarity = GeniusNameMatcher::score(
                $parsedAlbumComparisonTitle,
                $this->normalizeAlbumComparisonTitle($albumTitle),
            );
            $albumScore = $this->albumMatchAdjustment($parsedAlbumTitle, $albumTitle, $parsedTrack->title, $parsedTrack->title);
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $albumSummary);
            $nonOriginalAlbum = $this->hasNonOriginalAlbumMarkers($albumTitle);
            $compilation = $this->hasCompilationMarkers($albumTitle);
            $demo = $this->hasDemoMarkers($albumTitle);
            $unreleased = $this->hasUnreleasedMarkers($albumTitle);

            if ($albumSimilarity < 0.68 && $albumScore < -0.04) {
                continue;
            }

            $candidates[] = [
                'album' => $albumSummary,
                'album_title' => $albumTitle,
                'album_similarity' => $albumSimilarity,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'non_original_album' => $nonOriginalAlbum,
                'demo' => $demo,
                'compilation' => $compilation,
                'unreleased' => $unreleased,
                'prefer_original_album' => $preferOriginalAlbum,
                'release_sort' => $this->releaseSortValue($albumSummary),
                'score' => $albumSimilarity + $albumScore + $releaseYearScore + ($nonOriginalAlbum ? -0.12 : 0.04) + ($demo ? -0.08 : 0.02) + ($compilation ? -0.08 : 0.0) + ($unreleased ? -0.18 : 0.0),
            ];
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareAlbumTrackFallbackCandidates($left, $right));

        return $candidates;
    }

    private function compareAlbumTrackFallbackCandidates(array $left, array $right): int
    {
        $preferOriginalAlbum = (bool) ($left['prefer_original_album'] ?? false);
        $leftAlbumSimilarity = round((float) ($left['album_similarity'] ?? 0.0), 4);
        $rightAlbumSimilarity = round((float) ($right['album_similarity'] ?? 0.0), 4);

        if ($preferOriginalAlbum) {
            if (($left['non_original_album'] ?? false) !== ($right['non_original_album'] ?? false)) {
                return ((bool) ($left['non_original_album'] ?? false)) <=> ((bool) ($right['non_original_album'] ?? false));
            }

            if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
            }

            if (($left['unreleased'] ?? false) !== ($right['unreleased'] ?? false)) {
                return ((bool) ($left['unreleased'] ?? false)) <=> ((bool) ($right['unreleased'] ?? false));
            }

            if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
            }
        }

        $leftAlbumScore = round((float) ($left['album_score'] ?? 0.0), 4);
        $rightAlbumScore = round((float) ($right['album_score'] ?? 0.0), 4);

        if (! $preferOriginalAlbum && $leftAlbumSimilarity !== $rightAlbumSimilarity) {
            return $rightAlbumSimilarity <=> $leftAlbumSimilarity;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

        if ($leftReleaseYearScore !== $rightReleaseYearScore) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        if ($leftAlbumSimilarity !== $rightAlbumSimilarity) {
            return $rightAlbumSimilarity <=> $leftAlbumSimilarity;
        }

        if ($leftAlbumScore !== $rightAlbumScore) {
            return $rightAlbumScore <=> $leftAlbumScore;
        }

        return round((float) ($right['score'] ?? 0.0), 4) <=> round((float) ($left['score'] ?? 0.0), 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumCandidates
     */
    private function albumTrackFallbackInspectionLimit(array $albumCandidates): int
    {
        $strongCandidates = collect($albumCandidates)
            ->filter(function (array $candidate): bool {
                return (float) ($candidate['album_similarity'] ?? 0.0) >= 0.88
                    || (float) ($candidate['album_score'] ?? 0.0) >= 0.12;
            })
            ->count();

        return max(6, min(12, max(8, $strongCandidates + 1)));
    }

    private function parsedTrackPrefersOriginalAlbum(ParsedTrack $parsedTrack): bool
    {
        return array_intersect($this->parsedTrackVersionFlags($parsedTrack), [
            'instrumental',
            'karaoke',
            'live',
            'remix',
            'remixes',
            'acoustic',
            'edit',
            'ep',
            'deluxe',
            'ver2',
            'remaster',
            'ringtone',
            'slow',
            'reverb',
        ]) === [];
    }

    private function parsedTrackUsesTrackNumberSignal(ParsedTrack $parsedTrack): bool
    {
        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $parsedTrack->trackNumber !== null
            && $parsedTrack->trackNumber > 0
            && $parsedTrack->trackNumber <= 80;
    }

    private function directTrackNumberFromPayload(array $payload): ?int
    {
        $trackNumber = (int) (
            $payload['song_number']
                ?? $payload['track_number']
                ?? $payload['number']
                ?? data_get($payload, 'album_appearance.track_number')
                ?? 0
        );

        return $trackNumber > 0 && $trackNumber <= 80
            ? $trackNumber
            : null;
    }

    private function extractSongTrackNumber(array $payload): ?int
    {
        $directTrackNumber = $this->directTrackNumberFromPayload($payload);

        if ($directTrackNumber !== null) {
            return $directTrackNumber;
        }

        $albumId = (int) data_get($payload, 'album.id', 0);
        $songId = (int) ($payload['id'] ?? 0);

        if ($albumId <= 0 || $songId <= 0) {
            return null;
        }

        $trackNumbers = $this->geniusClient->albumTrackNumbers(
            $albumId,
            is_string(data_get($payload, 'album.url')) ? (string) data_get($payload, 'album.url') : null,
        );

        return isset($trackNumbers[$songId]) && (int) $trackNumbers[$songId] > 0 && (int) $trackNumbers[$songId] <= 80
            ? (int) $trackNumbers[$songId]
            : null;
    }

    private function trackNumberMatchAdjustment(ParsedTrack $parsedTrack, ?int $songTrackNumber): float
    {
        if (! $this->parsedTrackUsesTrackNumberSignal($parsedTrack) || $songTrackNumber === null) {
            return 0.0;
        }

        $delta = abs((int) $parsedTrack->trackNumber - $songTrackNumber);

        return match (true) {
            $delta === 0 => 0.18,
            $delta === 1 => 0.06,
            $delta === 2 => -0.06,
            default => -0.18,
        };
    }

    /**
     * @param  array<string, mixed>  $albumPayload
     * @return array<int, array<string, mixed>>
     */
    private function albumTrackSongs(int $albumId, array $albumPayload, ?string $fallbackTrackKey = null): array
    {
        if (array_key_exists($albumId, $this->albumTrackSongCache)) {
            if ($fallbackTrackKey !== null && isset($this->failedAlbumTrackLookupAlbumIds[$albumId])) {
                $this->fallbackNetworkFailuresByTrack[$fallbackTrackKey] = true;
            }

            return $this->albumTrackSongCache[$albumId];
        }

        try {
            $tracks = $this->geniusClient->albumTracks($albumId);
        } catch (Throwable $exception) {
            $this->failedAlbumTrackLookupAlbumIds[$albumId] = true;
            if ($fallbackTrackKey !== null) {
                $this->fallbackNetworkFailuresByTrack[$fallbackTrackKey] = true;
            }

            $this->matchingLog('warning', 'Genius album tracks lookup failed, cached as empty for this sync.', [
                'album_id' => $albumId,
                'exception' => $exception->getMessage(),
            ]);

            return $this->albumTrackSongCache[$albumId] = [];
        }

        return $this->albumTrackSongCache[$albumId] = $this->normalizeAlbumTrackSongs($albumId, $albumPayload, $tracks);
    }

    /**
     * @param  array<string, mixed>  $albumPayload
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAlbumTrackSongs(int $albumId, array $albumPayload, array $tracks): array
    {
        return collect($tracks)
            ->map(function (array $track) use ($albumPayload): ?array {
                $songPayload = is_array($track['song'] ?? null) ? $track['song'] : $track;

                if (! is_array($songPayload) || empty($songPayload['id'])) {
                    return null;
                }

                $albumName = (string) ($albumPayload['name'] ?? $albumPayload['title'] ?? '');
                $albumTitle = (string) ($albumPayload['title'] ?? $albumPayload['name'] ?? '');
                $albumUrl = (string) ($albumPayload['url'] ?? '');
                $albumReleaseDate = (string) ($albumPayload['release_date'] ?? '');
                $albumReleaseDateComponents = is_array($albumPayload['release_date_components'] ?? null)
                    ? $albumPayload['release_date_components']
                    : null;

                $songPayload['album'] = array_filter([
                    'id' => (int) ($albumPayload['id'] ?? 0),
                    'name' => $albumName,
                    'title' => $albumTitle,
                    'url' => $albumUrl,
                    'release_date' => $albumReleaseDate,
                    'release_date_components' => $albumReleaseDateComponents,
                ], fn ($value) => $value !== null && $value !== '' && $value !== []);

                if (! isset($songPayload['release_date']) && $albumReleaseDate !== '') {
                    $songPayload['release_date'] = $albumReleaseDate;
                }

                if (! is_array($songPayload['release_date_components'] ?? null) && $albumReleaseDateComponents !== null) {
                    $songPayload['release_date_components'] = $albumReleaseDateComponents;
                }

                $trackNumber = (int) ($track['number'] ?? $track['track_number'] ?? 0);

                if ($trackNumber > 0) {
                    $songPayload['track_number'] = $trackNumber;
                }

                $songPayload['_wf_source'] = 'album-track';

                return $songPayload;
            })
            ->filter(fn ($song) => is_array($song))
            ->unique(fn (array $song) => (int) ($song['id'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $songSummaries
     * @param  int[]  $usedSongIds
     * @return array<int, array<string, mixed>>
     */
    private function buildSongMatchCandidates(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $songSummaries,
        array $usedSongIds,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
    ): array {
        $candidates = [];
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);

        foreach ($songSummaries as $songSummary) {
            $summaryTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

            if ($summaryTitle === '' || ! $this->songContainsArtist($songSummary, $this->currentPageArtistReferenceNames !== [] ? $this->currentPageArtistReferenceNames : $this->pageArtistReferenceNames($page))) {
                continue;
            }

            $songId = (int) ($songSummary['id'] ?? 0);
            $songAlbumTitle = $this->extractSongAlbumTitle($songSummary);
            $songTitle = (string) ($songSummary['title'] ?? '');
            $titleScore = GeniusNameMatcher::score($parsedTitle, $summaryTitle);
            $versionScore = $this->versionMatchAdjustment($this->parsedTrackVersionContext($parsedTrack), (string) ($songSummary['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songSummary['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songSummary);
            $songTrackNumber = $this->directTrackNumberFromPayload($songSummary);
            $trackNumberScore = $this->trackNumberMatchAdjustment($parsedTrack, $songTrackNumber);
            $score = $titleScore + $versionScore + $albumScore + $releaseYearScore + $trackNumberScore;
            $isAlbumTrackSummary = (string) ($songSummary['_wf_source'] ?? '') === 'album-track';
            $summaryNonOriginal = ($songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false)
                || $this->hasNonOriginalAlbumMarkers($songTitle);
            $summaryDemo = ($songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false)
                || $this->hasDemoMarkers($songTitle);
            $summaryUnreleased = ($songAlbumTitle ? $this->hasUnreleasedMarkers($songAlbumTitle) : false)
                || $this->hasUnreleasedMarkers($songTitle);

            if ($isAlbumTrackSummary && $titleScore < 0.72) {
                continue;
            }

            if ($score < ($parsedAlbumTitle ? 0.66 : 0.76)) {
                continue;
            }

            $artistBoost = 0.04;
            $duplicatePenalty = in_array($songId, $usedSongIds, true) ? 0.06 : 0.0;
            $albumPresenceBoost = $parsedAlbumTitle && $songAlbumTitle ? 0.02 : 0.0;
            $titlePriority = $titleScore + $versionScore;

            $candidates[] = [
                'song' => $songSummary,
                'score' => $score + $artistBoost + $albumPresenceBoost - $duplicatePenalty,
                'title_score' => $titleScore,
                'title_priority' => $titlePriority,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'song_track_number' => $songTrackNumber,
                'track_number_score' => $trackNumberScore,
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'used' => in_array($songId, $usedSongIds, true),
                'exact_title' => $parsedTitle === $summaryTitle,
                'prefer_original_album' => $preferOriginalAlbum,
                'non_original_album' => $summaryNonOriginal,
                'demo' => $summaryDemo,
                'unreleased' => $summaryUnreleased,
                'compilation' => $songAlbumTitle ? $this->hasCompilationMarkers($songAlbumTitle) : false,
                'release_sort' => $this->releaseSortValue($songSummary),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function resolveSongMatchCandidates(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $candidates,
        string $parsedTitle,
        ?string $parsedAlbumTitle,
    ): array {
        $resolvedCandidates = [];
        $preferOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $detailResolutionLimit = $this->detailResolutionLimit($parsedTrack, $candidates);

        foreach (array_slice($candidates, 0, min($detailResolutionLimit, count($candidates))) as $candidate) {
            $songSummary = $candidate['song'] ?? null;

            if (! is_array($songSummary) || empty($songSummary['id'])) {
                continue;
            }

            $songDetail = $this->songDetail((int) $songSummary['id']);

            if (! is_array($songDetail)) {
                $songDetail = $songSummary;
            }

            $songDetail = $this->mergeSongDetailWithSummary($songDetail, $songSummary);

            if (! $this->songMatchesArtistPage($songDetail, $page)) {
                continue;
            }

            if (! $this->songTitleMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            if (! $this->sourceAlbumTracklistAllows($parsedTrack, (int) ($songDetail['id'] ?? 0))) {
                continue;
            }

            if ($this->sourceAlbumVersionConflictsWithSong($parsedTrack, $songDetail)) {
                continue;
            }

            $allowAlbumContextOverride = $this->shouldAllowAlbumPageContextAlbumOverride($parsedTrack, $songDetail);
            $allowTrackNumberContextMatch = $this->shouldAllowAlbumPageContextTrackNumberMatch($parsedTrack, $songDetail);

            if (! $allowAlbumContextOverride
                && ! $allowTrackNumberContextMatch
                && ! $this->songAlbumMatchesParsedTrack($parsedTrack, $songDetail)) {
                continue;
            }

            $songAlbumTitle = $this->extractSongAlbumTitle($songDetail);
            $detailTitleRaw = (string) ($songDetail['title'] ?? '');
            $detailTitle = GeniusNameMatcher::canonicalTrack((string) ($songDetail['title'] ?? ''));
            $titleScore = GeniusNameMatcher::score($parsedTitle, $detailTitle);
            $versionScore = $this->versionMatchAdjustment($this->parsedTrackVersionContext($parsedTrack), (string) ($songDetail['title'] ?? ''));
            $albumScore = $this->albumMatchAdjustment($parsedTrack->albumTitle, $songAlbumTitle, $parsedTrack->title, (string) ($songDetail['title'] ?? ''));
            $releaseYearScore = $this->releaseYearMatchAdjustment($parsedTrack->releaseYear, $songDetail);
            $songTrackNumber = $this->extractSongTrackNumber($songDetail);
            $trackNumberScore = $this->trackNumberMatchAdjustment($parsedTrack, $songTrackNumber);
            $detailNonOriginal = ($songAlbumTitle ? $this->hasNonOriginalAlbumMarkers($songAlbumTitle) : false)
                || $this->hasNonOriginalAlbumMarkers($detailTitleRaw);
            $detailDemo = ($songAlbumTitle ? $this->hasDemoMarkers($songAlbumTitle) : false)
                || $this->hasDemoMarkers($detailTitleRaw);
            $detailUnreleased = ($songAlbumTitle ? $this->hasUnreleasedMarkers($songAlbumTitle) : false)
                || $this->hasUnreleasedMarkers($detailTitleRaw);

            $resolvedCandidates[] = array_merge($candidate, [
                'summary' => $songSummary,
                'detail' => $songDetail,
                'score' => max((float) ($candidate['score'] ?? 0.0), $titleScore + $versionScore + $albumScore + $releaseYearScore + $trackNumberScore),
                'title_score' => $titleScore,
                'title_priority' => $titleScore + $versionScore,
                'album_score' => $albumScore,
                'release_year_score' => $releaseYearScore,
                'song_track_number' => $songTrackNumber,
                'track_number_score' => $trackNumberScore,
                'album_present' => $songAlbumTitle !== null,
                'album_preference' => $this->albumPreferenceValue($parsedAlbumTitle, $songAlbumTitle, $albumScore),
                'exact_title' => $parsedTitle === $detailTitle,
                'prefer_original_album' => $preferOriginalAlbum,
                'non_original_album' => $detailNonOriginal,
                'demo' => $detailDemo,
                'unreleased' => $detailUnreleased,
                'compilation' => $songAlbumTitle ? $this->hasCompilationMarkers($songAlbumTitle) : false,
                'release_sort' => $this->releaseSortValue($songDetail),
            ]);

            $resolvedCandidate = $resolvedCandidates[array_key_last($resolvedCandidates)] ?? null;

            if (is_array($resolvedCandidate) && $this->isDecisiveResolvedSongCandidate($parsedTrack, $resolvedCandidate)) {
                break;
            }
        }

        return $resolvedCandidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function detailResolutionLimit(ParsedTrack $parsedTrack, array $candidates): int
    {
        $baseline = $this->candidateResolutionLimit($parsedTrack);
        $topCandidate = $candidates[0] ?? null;

        if (! is_array($topCandidate)) {
            return $baseline;
        }

        if (! (bool) ($topCandidate['exact_title'] ?? false)) {
            return $baseline;
        }

        if ((float) ($topCandidate['title_priority'] ?? 0.0) >= 0.98
            && (int) ($topCandidate['album_preference'] ?? 0) >= 2
            && ! (bool) ($topCandidate['non_original_album'] ?? false)) {
            return min($baseline, 1);
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && (bool) ($topCandidate['non_original_album'] ?? false)) {
            return min($baseline, 3);
        }

        if ((int) ($topCandidate['album_preference'] ?? 0) >= 3) {
            return min($baseline, 2);
        }

        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            if (! (bool) ($topCandidate['album_present'] ?? false)) {
                return min($baseline, 3);
            }

            return min($baseline, 2);
        }

        return min($baseline, 3);
    }

    private function isDecisiveResolvedSongCandidate(ParsedTrack $parsedTrack, array $candidate): bool
    {
        if (! (bool) ($candidate['exact_title'] ?? false)) {
            return false;
        }

        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && ! (bool) ($candidate['album_present'] ?? false)) {
            return false;
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)) {
            if ((bool) ($candidate['non_original_album'] ?? false)
                || (bool) ($candidate['demo'] ?? false)
                || (bool) ($candidate['unreleased'] ?? false)
                || (bool) ($candidate['compilation'] ?? false)) {
                return false;
            }

            if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
                && (int) ($candidate['album_preference'] ?? 0) < 3) {
                return false;
            }
        }

        if ($parsedTrack->releaseYear !== null && (float) ($candidate['release_year_score'] ?? 0.0) < 0.0) {
            return false;
        }

        if ($this->parsedTrackUsesTrackNumberSignal($parsedTrack)
            && ($candidate['song_track_number'] ?? null) !== null
            && (float) ($candidate['track_number_score'] ?? 0.0) < 0.12) {
            return false;
        }

        return (float) ($candidate['title_priority'] ?? 0.0) >= 0.98;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function trimSongMatchCandidates(ParsedTrack $parsedTrack, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $topCandidate = $candidates[0] ?? [];
        $topTitlePriority = (float) ($topCandidate['title_priority'] ?? 0.0);
        $topScore = (float) ($topCandidate['score'] ?? 0.0);
        $scoreWindow = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle) ? 0.24 : 0.18;
        $titleWindow = $this->parsedTrackVersionFlags($parsedTrack) === [] ? 0.12 : 0.18;

        $filtered = collect($candidates)
            ->filter(function (array $candidate) use ($topScore, $topTitlePriority, $scoreWindow, $titleWindow): bool {
                if ((bool) ($candidate['exact_title'] ?? false)) {
                    return true;
                }

                if ((int) ($candidate['album_preference'] ?? 0) >= 3 && (float) ($candidate['album_score'] ?? 0.0) >= 0.12) {
                    return true;
                }

                return (float) ($candidate['title_priority'] ?? 0.0) >= ($topTitlePriority - $titleWindow)
                    && (float) ($candidate['score'] ?? 0.0) >= ($topScore - $scoreWindow);
            })
            ->values()
            ->all();

        if ($filtered === []) {
            $filtered = $candidates;
        }

        return array_slice($filtered, 0, $this->candidateResolutionLimit($parsedTrack));
    }

    private function candidateResolutionLimit(ParsedTrack $parsedTrack): int
    {
        if ($this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return $this->parsedTrackVersionFlags($parsedTrack) === [] ? 6 : 8;
        }

        return $this->parsedTrackVersionFlags($parsedTrack) === [] ? 4 : 6;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function songDetail(int $songId): ?array
    {
        if (! array_key_exists($songId, $this->songDetails)) {
            try {
                $this->songDetails[$songId] = $this->geniusClient->song($songId);
            } catch (Throwable $exception) {
                $this->songDetails[$songId] = null;

                $this->matchingLog('warning', 'Genius song detail lookup failed, cached as null for this sync.', [
                    'song_id' => $songId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $songDetail = $this->songDetails[$songId];

        return is_array($songDetail) ? $songDetail : null;
    }

    /**
     * @param  array<string, mixed>  $songDetail
     * @param  array<string, mixed>  $songSummary
     * @return array<string, mixed>
     */
    private function mergeSongDetailWithSummary(array $songDetail, array $songSummary): array
    {
        $songDetail = $this->mergeSongArtistCreditSources($songDetail, $songSummary);

        if ((string) ($songSummary['_wf_source'] ?? '') !== 'album-track') {
            return $songDetail;
        }

        $summaryAlbum = is_array($songSummary['album'] ?? null) ? $songSummary['album'] : null;

        if ($summaryAlbum !== null) {
            $songDetail['album'] = array_replace(
                is_array($songDetail['album'] ?? null) ? $songDetail['album'] : [],
                $summaryAlbum,
            );
        }

        foreach (['release_date', 'release_date_components', 'track_number'] as $key) {
            if (array_key_exists($key, $songSummary) && $songSummary[$key] !== null && $songSummary[$key] !== '') {
                $songDetail[$key] = $songSummary[$key];
            }
        }

        return $songDetail;
    }

    /**
     * Preserve artist credits exposed by either Genius endpoint. Search summaries
     * and song details can disagree on whether credits are represented as a
     * plural ID-backed list or a singular primary artist.
     *
     * @param  array<string, mixed>  $songDetail
     * @param  array<string, mixed>  $songSummary
     * @return array<string, mixed>
     */
    private function mergeSongArtistCreditSources(array $songDetail, array $songSummary): array
    {
        $creditNames = is_array($songDetail['_wf_credit_names'] ?? null)
            ? $songDetail['_wf_credit_names']
            : [];

        foreach ([
            'primary_artist_names' => 'primary',
            'featured_artist_names' => 'featured',
            'artist_names' => 'all',
        ] as $field => $role) {
            foreach ([$songDetail[$field] ?? null, $songSummary[$field] ?? null] as $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $creditNames[$role][] = $value;
                }
            }
        }

        $artistPayloads = [
            'primary_artists' => [
                ...$this->geniusArtistPayloadList($songDetail['primary_artists'] ?? null),
                ...array_filter([is_array($songDetail['primary_artist'] ?? null) ? $songDetail['primary_artist'] : null]),
                ...$this->geniusArtistPayloadList($songSummary['primary_artists'] ?? null),
                ...array_filter([is_array($songSummary['primary_artist'] ?? null) ? $songSummary['primary_artist'] : null]),
            ],
            'featured_artists' => [
                ...$this->geniusArtistPayloadList($songDetail['featured_artists'] ?? null),
                ...$this->geniusArtistPayloadList($songSummary['featured_artists'] ?? null),
            ],
        ];

        foreach ($artistPayloads as $field => $payloads) {
            $mergedPayloads = collect($payloads)
                ->filter(fn ($artist): bool => is_array($artist) && (int) ($artist['id'] ?? 0) > 0)
                ->unique(fn (array $artist): int => (int) $artist['id'])
                ->values()
                ->all();

            if ($mergedPayloads !== []) {
                $songDetail[$field] = $mergedPayloads;
            }
        }

        if (! is_array($songDetail['primary_artist'] ?? null)
            && is_array($songSummary['primary_artist'] ?? null)) {
            $songDetail['primary_artist'] = $songSummary['primary_artist'];
        }

        if ($creditNames !== []) {
            $songDetail['_wf_credit_names'] = $creditNames;
        }

        return $songDetail;
    }

    /** @return array<int, array<string, mixed>> */
    private function geniusArtistPayloadList(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['id'])) {
            return [$payload];
        }

        return collect($payload)
            ->filter(fn ($artist): bool => is_array($artist) && isset($artist['id']))
            ->values()
            ->all();
    }

    private function compareSongMatchCandidates(array $left, array $right): int
    {
        if (($left['used'] ?? false) !== ($right['used'] ?? false)) {
            return ((bool) ($left['used'] ?? false)) <=> ((bool) ($right['used'] ?? false));
        }

        $leftExactTitle = (bool) ($left['exact_title'] ?? false);
        $rightExactTitle = (bool) ($right['exact_title'] ?? false);

        if ($leftExactTitle !== $rightExactTitle) {
            return $rightExactTitle <=> $leftExactTitle;
        }

        $leftReleaseYearScore = round((float) ($left['release_year_score'] ?? 0.0), 4);
        $rightReleaseYearScore = round((float) ($right['release_year_score'] ?? 0.0), 4);

        if ($leftExactTitle
            && $rightExactTitle
            && abs($leftReleaseYearScore - $rightReleaseYearScore) >= 0.09) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftAlbumPreference = (int) ($left['album_preference'] ?? 0);
        $rightAlbumPreference = (int) ($right['album_preference'] ?? 0);

        if ($leftExactTitle && $rightExactTitle && $leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        $preferOriginalAlbum = (bool) ($left['prefer_original_album'] ?? false);

        if ($preferOriginalAlbum) {
            $leftNonOriginalAlbum = (bool) ($left['non_original_album'] ?? false);
            $rightNonOriginalAlbum = (bool) ($right['non_original_album'] ?? false);

            if ($leftNonOriginalAlbum !== $rightNonOriginalAlbum
                && min($leftAlbumPreference, $rightAlbumPreference) >= 1) {
                return $leftNonOriginalAlbum <=> $rightNonOriginalAlbum;
            }

            if ($leftAlbumPreference !== $rightAlbumPreference) {
                return $rightAlbumPreference <=> $leftAlbumPreference;
            }

            if ($leftNonOriginalAlbum !== $rightNonOriginalAlbum) {
                return $leftNonOriginalAlbum <=> $rightNonOriginalAlbum;
            }

            $leftDemo = (bool) ($left['demo'] ?? false);
            $rightDemo = (bool) ($right['demo'] ?? false);

            if ($leftDemo !== $rightDemo) {
                return $leftDemo <=> $rightDemo;
            }

            $leftUnreleased = (bool) ($left['unreleased'] ?? false);
            $rightUnreleased = (bool) ($right['unreleased'] ?? false);

            if ($leftUnreleased !== $rightUnreleased) {
                return $leftUnreleased <=> $rightUnreleased;
            }

            $leftCompilation = (bool) ($left['compilation'] ?? false);
            $rightCompilation = (bool) ($right['compilation'] ?? false);

            if ($leftCompilation !== $rightCompilation) {
                return $leftCompilation <=> $rightCompilation;
            }

            $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
            $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

            if ($leftReleaseSort !== $rightReleaseSort) {
                return $leftReleaseSort <=> $rightReleaseSort;
            }
        }

        $leftTrackNumberScore = round((float) ($left['track_number_score'] ?? 0.0), 4);
        $rightTrackNumberScore = round((float) ($right['track_number_score'] ?? 0.0), 4);

        if ($leftTrackNumberScore !== $rightTrackNumberScore) {
            return $rightTrackNumberScore <=> $leftTrackNumberScore;
        }

        $leftTitlePriority = round((float) ($left['title_priority'] ?? 0.0), 4);
        $rightTitlePriority = round((float) ($right['title_priority'] ?? 0.0), 4);

        if ($leftTitlePriority !== $rightTitlePriority) {
            return $rightTitlePriority <=> $leftTitlePriority;
        }

        if ($leftAlbumPreference !== $rightAlbumPreference) {
            return $rightAlbumPreference <=> $leftAlbumPreference;
        }

        if ($leftReleaseYearScore !== $rightReleaseYearScore) {
            return $rightReleaseYearScore <=> $leftReleaseYearScore;
        }

        $leftNonOriginalAlbum = (bool) ($left['non_original_album'] ?? false);
        $rightNonOriginalAlbum = (bool) ($right['non_original_album'] ?? false);

        if ($leftNonOriginalAlbum !== $rightNonOriginalAlbum) {
            return $leftNonOriginalAlbum <=> $rightNonOriginalAlbum;
        }

        $leftDemo = (bool) ($left['demo'] ?? false);
        $rightDemo = (bool) ($right['demo'] ?? false);

        if ($leftDemo !== $rightDemo) {
            return $leftDemo <=> $rightDemo;
        }

        $leftUnreleased = (bool) ($left['unreleased'] ?? false);
        $rightUnreleased = (bool) ($right['unreleased'] ?? false);

        if ($leftUnreleased !== $rightUnreleased) {
            return $leftUnreleased <=> $rightUnreleased;
        }

        $leftCompilation = (bool) ($left['compilation'] ?? false);
        $rightCompilation = (bool) ($right['compilation'] ?? false);

        if ($leftCompilation !== $rightCompilation) {
            return $leftCompilation <=> $rightCompilation;
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        return round((float) ($right['score'] ?? 0.0), 4) <=> round((float) ($left['score'] ?? 0.0), 4);
    }

    private function albumPreferenceValue(?string $parsedAlbumTitle, ?string $songAlbumTitle, float $albumScore): int
    {
        if ($songAlbumTitle === null || $songAlbumTitle === '') {
            return $parsedAlbumTitle ? -1 : 0;
        }

        if (! $parsedAlbumTitle) {
            return 2;
        }

        if ($albumScore >= 0.12) {
            return 3;
        }

        if ($albumScore >= -0.04) {
            return 2;
        }

        if ($albumScore > -0.16) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaryCandidates
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     */
    private function shouldSearchForBetterSongMatch(?string $parsedAlbumTitle, array $summaryCandidates, array $resolvedCandidates): bool
    {
        if ($resolvedCandidates === []) {
            return true;
        }

        usort($resolvedCandidates, fn (array $left, array $right): int => $this->compareSongMatchCandidates($left, $right));
        $bestCandidate = $resolvedCandidates[0] ?? null;

        if (! is_array($bestCandidate)) {
            return true;
        }

        if ((float) ($bestCandidate['score'] ?? 0.0) < 0.9 || (float) ($bestCandidate['title_priority'] ?? 0.0) < 0.965) {
            return true;
        }

        if ($parsedAlbumTitle && (int) ($bestCandidate['album_preference'] ?? 0) < 2) {
            return true;
        }

        if ((bool) ($bestCandidate['album_present'] ?? false)) {
            return false;
        }

        if ($parsedAlbumTitle) {
            return true;
        }

        return collect($summaryCandidates)
            ->filter(fn (array $candidate) => (bool) ($candidate['exact_title'] ?? false))
            ->count() >= 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pools
     * @return array<int, array<string, mixed>>
     */
    private function mergeSongSummaries(array ...$pools): array
    {
        $merged = [];

        foreach ($pools as $pool) {
            foreach ($pool as $songSummary) {
                if (! is_array($songSummary) || empty($songSummary['id'])) {
                    continue;
                }

                $songId = (int) $songSummary['id'];
                $current = $merged[$songId] ?? null;

                if (! is_array($current)) {
                    $merged[$songId] = $songSummary;

                    continue;
                }

                $currentRichness = $this->songSummaryRichness($current);
                $incomingRichness = $this->songSummaryRichness($songSummary);

                $merged[$songId] = $incomingRichness > $currentRichness
                    ? array_replace_recursive($current, $songSummary)
                    : array_replace_recursive($songSummary, $current);
            }
        }

        return array_values($merged);
    }

    private function songSummaryRichness(array $songSummary): int
    {
        $score = 0;

        foreach ([
            'album.id',
            'album.name',
            'album.url',
            'release_date',
            'release_date_components.year',
            'primary_artist.id',
            'primary_artist.name',
            'artist_names',
            'primary_artist_names',
        ] as $path) {
            $value = data_get($songSummary, $path);

            if ($value !== null && $value !== '') {
                $score++;
            }
        }

        return $score + count((array) ($songSummary['primary_artists'] ?? [])) + count((array) ($songSummary['featured_artists'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaryCandidates
     * @param  array<int, array<string, mixed>>  $resolvedCandidates
     * @param  array{summary: array<string, mixed>, detail: array<string, mixed>}|null  $winner
     */
    private function logSongMatchDiagnostics(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $summaryCandidates,
        array $resolvedCandidates,
        ?array $winner,
        string $source,
    ): void {
        $this->matchingLog('info', 'Genius track match decision.', [
            'artist' => $page->artistName,
            'artist_slug' => $page->artistSlug,
            'muzofond_track' => [
                'title' => GeniusNameMatcher::storageValue($parsedTrack->title),
                'album' => $parsedTrack->albumTitle,
                'track_number' => $parsedTrack->trackNumber,
                'release_year' => $parsedTrack->releaseYear,
                'source_type' => $parsedTrack->sourceType,
            ],
            'decision_source' => $source,
            'summary_candidate_count' => count($summaryCandidates),
            'resolved_candidate_count' => count($resolvedCandidates),
            'winner' => $winner ? [
                'genius_id' => (int) data_get($winner, 'summary.id', 0),
                'title' => GeniusNameMatcher::storageValue((string) data_get($winner, 'detail.title', '')),
                'artists' => $this->searchHitDiagnosticPayload((array) ($winner['detail'] ?? []))['artists'],
                'album' => $this->extractSongAlbumTitle((array) ($winner['detail'] ?? [])),
                'release_year' => (int) data_get($winner, 'detail.release_date_components.year', 0),
            ] : null,
        ]);

        if (! $this->debugMatchingEnabled()) {
            return;
        }

        $this->matchingLog('debug', 'Genius song match diagnostics.', [
            'source' => $source,
            'artist' => $page->artistName,
            'parsed_track' => [
                'title' => $parsedTrack->title,
                'album' => $parsedTrack->albumTitle,
                'year' => $parsedTrack->releaseYear,
                'artists' => $parsedTrack->artistNames,
            ],
            'summary_candidates' => collect($summaryCandidates)
                ->take(8)
                ->map(fn (array $candidate) => $this->songCandidateDebugPayload($candidate, 'song'))
                ->values()
                ->all(),
            'resolved_candidates' => collect($resolvedCandidates)
                ->take(8)
                ->map(fn (array $candidate) => $this->songCandidateDebugPayload($candidate, 'detail'))
                ->values()
                ->all(),
            'winner' => $winner ? [
                'song_id' => (int) data_get($winner, 'summary.id', 0),
                'title' => (string) data_get($winner, 'detail.title', ''),
                'album' => $this->extractSongAlbumTitle((array) ($winner['detail'] ?? [])),
                'release_sort' => $this->releaseSortValue((array) ($winner['detail'] ?? [])),
                'release_year' => (int) data_get($winner, 'detail.release_date_components.year', 0),
            ] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $song
     * @return array<string, mixed>
     */
    private function searchHitDiagnosticPayload(array $song): array
    {
        $artists = collect($this->songArtistNames($song));

        if ($artists->isEmpty() && is_array($song['primary_artist'] ?? null)) {
            $primaryArtist = GeniusNameMatcher::storageValue((string) data_get($song, 'primary_artist.name', ''));

            if ($primaryArtist !== '') {
                $artists->push($primaryArtist);
            }
        }

        if ($artists->isEmpty()) {
            $artists = collect((array) ($song['artist_names'] ?? []))
                ->map(fn ($artist): string => GeniusNameMatcher::storageValue(is_array($artist)
                    ? (string) ($artist['name'] ?? '')
                    : (string) $artist))
                ->filter()
                ->values();
        }

        $releaseYear = (int) data_get($song, 'release_date_components.year', 0);

        return [
            'genius_id' => (int) ($song['id'] ?? 0),
            'title' => mb_substr(GeniusNameMatcher::storageValue((string) ($song['title'] ?? '')), 0, 180),
            'artists' => $artists->take(6)->all(),
            'album' => $this->extractSongAlbumTitle($song),
            'release_year' => $releaseYear > 0 ? $releaseYear : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumCandidates
     * @param  array<int, array<string, mixed>>  $inspectedAlbums
     * @param  array{summary: array<string, mixed>, detail: array<string, mixed>}|null  $winner
     */
    private function logAlbumTrackFallbackDiagnostics(
        ParsedArtistPage $page,
        ParsedTrack $parsedTrack,
        array $albumCandidates,
        array $inspectedAlbums,
        ?array $winner,
    ): void {
        if (! $this->debugMatchingEnabled()) {
            return;
        }

        $this->matchingLog('debug', 'Genius album-track fallback diagnostics.', [
            'artist' => $page->artistName,
            'parsed_track' => [
                'title' => $parsedTrack->title,
                'album' => $parsedTrack->albumTitle,
                'year' => $parsedTrack->releaseYear,
            ],
            'album_candidates' => collect($albumCandidates)
                ->take(6)
                ->map(fn (array $candidate) => $this->albumFallbackCandidateDebugPayload($candidate))
                ->values()
                ->all(),
            'inspected_albums' => collect($inspectedAlbums)
                ->map(function (array $entry): array {
                    $candidate = is_array($entry['album'] ?? null) ? $entry['album'] : [];

                    return array_merge(
                        $this->albumFallbackCandidateDebugPayload($candidate),
                        ['tracks' => (int) ($entry['tracks'] ?? 0)],
                    );
                })
                ->values()
                ->all(),
            'winner' => $winner ? [
                'song_id' => (int) data_get($winner, 'summary.id', 0),
                'title' => (string) data_get($winner, 'detail.title', ''),
                'album' => $this->extractSongAlbumTitle((array) ($winner['detail'] ?? [])),
                'release_sort' => $this->releaseSortValue((array) ($winner['detail'] ?? [])),
            ] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function songCandidateDebugPayload(array $candidate, string $payloadKey): array
    {
        $payload = is_array($candidate[$payloadKey] ?? null) ? $candidate[$payloadKey] : [];

        return [
            'song_id' => (int) ($payload['id'] ?? 0),
            'title' => (string) ($payload['title'] ?? ''),
            'album' => $this->extractSongAlbumTitle($payload),
            'score' => round((float) ($candidate['score'] ?? 0.0), 4),
            'title_priority' => round((float) ($candidate['title_priority'] ?? 0.0), 4),
            'album_score' => round((float) ($candidate['album_score'] ?? 0.0), 4),
            'release_year_score' => round((float) ($candidate['release_year_score'] ?? 0.0), 4),
            'release_sort' => (int) ($candidate['release_sort'] ?? PHP_INT_MAX),
            'exact_title' => (bool) ($candidate['exact_title'] ?? false),
            'album_preference' => (int) ($candidate['album_preference'] ?? 0),
            'non_original_album' => (bool) ($candidate['non_original_album'] ?? false),
            'demo' => (bool) ($candidate['demo'] ?? false),
            'compilation' => (bool) ($candidate['compilation'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function albumFallbackCandidateDebugPayload(array $candidate): array
    {
        $payload = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];

        return [
            'album_id' => (int) ($payload['id'] ?? 0),
            'title' => (string) ($candidate['album_title'] ?? $payload['name'] ?? $payload['title'] ?? ''),
            'album_similarity' => round((float) ($candidate['album_similarity'] ?? 0.0), 4),
            'album_score' => round((float) ($candidate['album_score'] ?? 0.0), 4),
            'release_year_score' => round((float) ($candidate['release_year_score'] ?? 0.0), 4),
            'release_sort' => (int) ($candidate['release_sort'] ?? PHP_INT_MAX),
            'non_original_album' => (bool) ($candidate['non_original_album'] ?? false),
            'demo' => (bool) ($candidate['demo'] ?? false),
            'compilation' => (bool) ($candidate['compilation'] ?? false),
        ];
    }

    /**
     * @return string[]
     */
    private function pageArtistReferenceNames(ParsedArtistPage $page): array
    {
        $preferredName = $this->preferredPageArtistStorageName($page);
        $slugVariant = GeniusNameMatcher::storageValue(Str::headline(str_replace(['-', '_'], ' ', $page->artistSlug)));
        $references = [
            $preferredName,
            GeniusNameMatcher::storageValue($page->artistName),
            $slugVariant,
        ];

        return collect($references)
            ->filter()
            ->unique(fn (string $value) => Str::lower($value))
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $referenceNames
     * @param  string[]  $candidateNames
     */
    private function bestReferenceArtistScore(array $referenceNames, array $candidateNames): float
    {
        $bestScore = 0.0;

        foreach ($referenceNames as $referenceName) {
            $bestScore = max($bestScore, GeniusNameMatcher::bestArtistScore($referenceName, $candidateNames));
        }

        return $bestScore;
    }

    /**
     * @param  string[]  $referenceNames
     * @param  string[]  $candidateNames
     */
    private function hasExactReferenceArtistMatch(array $referenceNames, array $candidateNames): bool
    {
        foreach ($referenceNames as $referenceName) {
            if (GeniusNameMatcher::bestArtistScore($referenceName, $candidateNames) >= 0.985) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]  $referenceNames
     */
    private function artistCandidateCollectivePenalty(array $referenceNames, string ...$candidateNames): float
    {
        $meaningfulReferenceNames = collect($referenceNames)
            ->filter()
            ->values();

        if ($meaningfulReferenceNames->isEmpty()) {
            return 0.0;
        }

        if ($meaningfulReferenceNames->every(fn (string $name) => $this->hasCollectiveArtistMarkers($name))) {
            return 0.0;
        }

        return collect($candidateNames)
            ->filter()
            ->contains(fn (string $name) => $this->hasCollectiveArtistMarkers($name))
            ? 0.14
            : 0.0;
    }

    private function hasCollectiveArtistMarkers(string $value): bool
    {
        return preg_match('/\b(?:family|records?|label|crew|collective|squad|gang|ent(?:ertainment)?)\b/iu', GeniusNameMatcher::normalizeLoose($value)) === 1;
    }

    private function preferredPageArtistStorageName(ParsedArtistPage $page): string
    {
        $pageName = GeniusNameMatcher::storageValue($page->artistName);
        $slugVariant = GeniusNameMatcher::storageValue(Str::headline(str_replace(['-', '_'], ' ', $page->artistSlug)));

        if ($slugVariant === '' || ! $this->hasCollectiveArtistMarkers($pageName) || $this->hasCollectiveArtistMarkers($slugVariant)) {
            return $pageName;
        }

        $normalizedPageName = GeniusNameMatcher::normalizeLoose($pageName);
        $normalizedSlug = GeniusNameMatcher::normalizeLoose($slugVariant);

        if ($normalizedPageName !== '' && $normalizedSlug !== '' && str_contains($normalizedPageName, $normalizedSlug)) {
            return $slugVariant;
        }

        return $pageName;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchArtist(ParsedArtistPage $page): ?array
    {
        $referenceNames = $this->pageArtistReferenceNames($page);
        $searchQueries = collect($referenceNames)
            ->flatMap(fn (string $referenceName) => GeniusNameMatcher::artistSearchQueries($referenceName))
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $candidates = collect($searchQueries)
                ->flatMap(fn (string $query) => $this->geniusClient->searchArtist($query))
                ->unique(fn (array $candidate) => (int) ($candidate['id'] ?? 0))
                ->map(function (array $candidate) use ($referenceNames): array {
                    $candidateNames = array_values(array_filter([(string) ($candidate['name'] ?? '')]));

                    return [
                        'candidate' => $candidate,
                        'name_score' => $this->bestReferenceArtistScore($referenceNames, $candidateNames),
                        'exact_alias' => $this->hasExactReferenceArtistMatch($referenceNames, $candidateNames),
                        'collective_penalty' => $this->artistCandidateCollectivePenalty($referenceNames, ...$candidateNames),
                    ];
                })
                ->sort(function (array $left, array $right): int {
                    $leftExactAlias = (bool) ($left['exact_alias'] ?? false);
                    $rightExactAlias = (bool) ($right['exact_alias'] ?? false);

                    if ($leftExactAlias !== $rightExactAlias) {
                        return $rightExactAlias <=> $leftExactAlias;
                    }

                    $leftNameScore = round((float) ($left['name_score'] ?? 0.0), 4);
                    $rightNameScore = round((float) ($right['name_score'] ?? 0.0), 4);

                    if ($leftNameScore !== $rightNameScore) {
                        return $rightNameScore <=> $leftNameScore;
                    }

                    return round((float) ($left['collective_penalty'] ?? 0.0), 4) <=> round((float) ($right['collective_penalty'] ?? 0.0), 4);
                })
                ->take(8)
                ->values();
        } catch (Throwable $exception) {
            $this->geniusUnavailable = true;
            $this->matchingLog('warning', 'Genius artist search is unavailable; saving Muzofond tracks as fallback.', [
                'artist' => $page->artistName,
                'artist_slug' => $page->artistSlug,
                'search_query_count' => count($searchQueries),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        $topCandidate = $candidates->first();

        if (is_array($topCandidate)
            && (bool) ($topCandidate['exact_alias'] ?? false)
            && (float) ($topCandidate['name_score'] ?? 0.0) >= 0.985
            && (float) ($topCandidate['collective_penalty'] ?? 0.0) <= 0.0
            && is_array($topCandidate['candidate'] ?? null)) {
            $artist = $this->geniusClient->artist((int) ($topCandidate['candidate']['id'] ?? 0));

            if (is_array($artist)) {
                return $artist;
            }
        }

        $sampleTrackTitles = collect($page->tracks)
            ->take(10)
            ->map(fn (ParsedTrack $track) => GeniusNameMatcher::canonicalTrack($track->title))
            ->filter()
            ->values()
            ->all();

        $bestScore = 0.0;
        $bestArtist = null;
        $bestExactAliasMatch = false;
        $bestOverlap = 0;

        foreach ($candidates as $candidate) {
            $candidatePayload = is_array($candidate['candidate'] ?? null) ? $candidate['candidate'] : [];
            $artist = $this->geniusClient->artist((int) ($candidatePayload['id'] ?? 0));

            if (! $artist) {
                continue;
            }

            $aliases = array_merge(
                [(string) ($candidatePayload['name'] ?? '')],
                [(string) ($artist['name'] ?? '')],
                array_values((array) ($artist['alternate_names'] ?? [])),
            );

            $nameScore = $this->bestReferenceArtistScore($referenceNames, $aliases);
            $overlap = $this->calculateSongOverlap((int) $artist['id'], $sampleTrackTitles);
            $exactAliasMatch = $this->hasExactReferenceArtistMatch($referenceNames, $aliases);
            $score = $nameScore + ($exactAliasMatch ? 0.16 : 0.0) - $this->artistCandidateCollectivePenalty($referenceNames, ...$aliases);

            if ($overlap >= 3) {
                $score += 0.16;
            } elseif ($overlap === 2) {
                $score += 0.12;
            } elseif ($overlap === 1) {
                $score += 0.05;
            } elseif ($nameScore < 0.9) {
                $score -= 0.08;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestArtist = $artist;
                $bestExactAliasMatch = $exactAliasMatch;
                $bestOverlap = $overlap;
            }
        }

        if (! $bestArtist) {
            return null;
        }

        if ($bestScore >= 0.86) {
            return $bestArtist;
        }

        if ($bestExactAliasMatch && $bestScore >= 0.76) {
            return $bestArtist;
        }

        if ($bestOverlap >= 2 && $bestScore >= 0.78) {
            return $bestArtist;
        }

        return null;
    }

    /**
     * @param  string[]  $sampleTrackTitles
     */
    private function calculateSongOverlap(int $artistId, array $sampleTrackTitles): int
    {
        if ($sampleTrackTitles === []) {
            return 0;
        }

        if (! array_key_exists($artistId, $this->artistSongTitleCatalogCache)) {
            $this->artistSongTitleCatalogCache[$artistId] = collect($this->geniusClient->allArtistSongs($artistId))
                ->take(80)
                ->map(fn (array $song) => GeniusNameMatcher::canonicalTrack((string) ($song['title'] ?? '')))
                ->filter()
                ->flip()
                ->map(fn () => true)
                ->all();
        }

        return collect($sampleTrackTitles)
            ->filter(fn (string $title) => isset($this->artistSongTitleCatalogCache[$artistId][$title]))
            ->count();
    }

    /**
     * @param  string[]  $artistReferenceNames
     */
    private function songContainsArtist(array $songSummary, array $artistReferenceNames): bool
    {
        $artistNames = $this->songArtistNames($songSummary, $artistReferenceNames);

        return $this->bestReferenceArtistScore($artistReferenceNames, $artistNames) >= 0.8;
    }

    /**
     * Genius uses both singular and plural artist fields across endpoints. Normalize all
     * of them before matching so a valid search hit is not silently discarded.
     *
     * @param  string[]  $artistReferenceNames
     * @return string[]
     */
    private function songArtistNames(array $songSummary, array $artistReferenceNames = []): array
    {
        $names = [];
        $referenceName = $artistReferenceNames[0] ?? null;

        $append = function (mixed $value) use (&$append, &$names, $referenceName): void {
            if (is_string($value)) {
                foreach (GeniusNameMatcher::splitArtistCredits($value, $referenceName) as $name) {
                    $names[] = $name;
                }

                return;
            }

            if (! is_array($value)) {
                return;
            }

            if (isset($value['name']) && is_string($value['name'])) {
                $names[] = $value['name'];

                return;
            }

            foreach ($value as $item) {
                $append($item);
            }
        };

        foreach ([
            'primary_artists',
            'featured_artists',
            'primary_artist',
            'artist_names',
            'primary_artist_names',
            'featured_artist_names',
        ] as $key) {
            if (array_key_exists($key, $songSummary)) {
                $append($songSummary[$key]);
            }
        }

        if (is_array($songSummary['_wf_credit_names'] ?? null)) {
            $append($songSummary['_wf_credit_names']);
        }

        return collect($names)
            ->map(fn (string $name): string => GeniusNameMatcher::storageValue($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name, 'UTF-8'))
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $artistReferenceNames
     */
    private function songPrimaryArtistMatchesPage(array $songSummary, array $artistReferenceNames): bool
    {
        $primaryArtistNames = collect((array) ($songSummary['primary_artists'] ?? []))
            ->map(fn ($artist) => is_array($artist) ? (string) ($artist['name'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        if ($primaryArtistNames === [] && is_array($songSummary['primary_artist'] ?? null)) {
            $primaryArtistNames[] = (string) ($songSummary['primary_artist']['name'] ?? '');
        }

        if ($primaryArtistNames === [] && is_string($songSummary['primary_artist_names'] ?? null)) {
            $primaryArtistNames = GeniusNameMatcher::splitArtistCredits((string) $songSummary['primary_artist_names'], $artistReferenceNames[0] ?? null);
        }

        return $this->bestReferenceArtistScore($artistReferenceNames, $primaryArtistNames) >= 0.82;
    }

    /**
     * Resolve name-only Genius credits to existing local artist rows. This is
     * deliberately exact after Unicode/case normalization: fuzzy artist matching
     * here could attach a track to the wrong performer.
     *
     * @param  array<string, mixed>  $songPayload
     * @return array{primary: EloquentCollection<int, Artist>, featured: EloquentCollection<int, Artist>}
     */
    private function resolveNameOnlyTrackCredits(array $songPayload): array
    {
        $summaryCredits = is_array($songPayload['_wf_credit_names'] ?? null)
            ? $songPayload['_wf_credit_names']
            : [];

        $primaryNames = array_merge(
            $this->flattenArtistCreditNames($songPayload['primary_artist_names'] ?? null),
            $this->flattenArtistCreditNames($summaryCredits['primary'] ?? null),
        );
        $featuredNames = array_merge(
            $this->flattenArtistCreditNames($songPayload['featured_artist_names'] ?? null),
            $this->flattenArtistCreditNames($summaryCredits['featured'] ?? null),
        );

        if (is_array($songPayload['primary_artist'] ?? null)
            && GeniusNameMatcher::storageValue((string) ($songPayload['primary_artist']['name'] ?? '')) !== '') {
            $primaryNames[] = (string) $songPayload['primary_artist']['name'];
        }

        foreach ((array) ($songPayload['primary_artists'] ?? []) as $artistPayload) {
            if (is_array($artistPayload) && GeniusNameMatcher::storageValue((string) ($artistPayload['name'] ?? '')) !== '') {
                $primaryNames[] = (string) $artistPayload['name'];
            }
        }

        foreach ((array) ($songPayload['featured_artists'] ?? []) as $artistPayload) {
            if (is_array($artistPayload) && GeniusNameMatcher::storageValue((string) ($artistPayload['name'] ?? '')) !== '') {
                $featuredNames[] = (string) $artistPayload['name'];
            }
        }

        [$genericPrimaryNames, $genericFeaturedNames] = $this->splitGenericArtistCreditNames(array_merge(
            $this->flattenArtistCreditNames($songPayload['artist_names'] ?? null),
            $this->flattenArtistCreditNames($summaryCredits['all'] ?? null),
        ));

        $primary = $this->artistsFromCreditNames(array_merge($primaryNames, $genericPrimaryNames));
        $featured = $this->artistsFromCreditNames(array_merge($featuredNames, $genericFeaturedNames));

        $featuredIds = $featured->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $primary = new EloquentCollection(
            $primary
                ->reject(fn (Artist $artist): bool => in_array((int) $artist->id, $featuredIds, true))
                ->values()
                ->all()
        );

        return [
            'primary' => $this->collapseExpandedCollectiveCredits($primary),
            'featured' => $featured,
        ];
    }

    private function hasCompleteGeniusArtistCredits(array $songPayload): bool
    {
        if (count($this->geniusArtistPayloadList($songPayload['primary_artists'] ?? null)) > 1
            || $this->geniusArtistPayloadList($songPayload['featured_artists'] ?? null) !== []) {
            return true;
        }

        $summaryCredits = is_array($songPayload['_wf_credit_names'] ?? null)
            ? $songPayload['_wf_credit_names']
            : [];

        foreach ([
            $songPayload['primary_artist_names'] ?? null,
            $songPayload['featured_artist_names'] ?? null,
            data_get($songPayload, '_wf_credit_names.primary'),
            data_get($songPayload, '_wf_credit_names.featured'),
        ] as $creditNames) {
            if ($this->flattenArtistCreditNames($creditNames) !== []) {
                return true;
            }
        }

        $genericNames = array_merge(
            $this->flattenArtistCreditNames($songPayload['artist_names'] ?? null),
            $this->flattenArtistCreditNames($summaryCredits['all'] ?? null),
        );
        $genericArtists = $this->artistsFromCreditNames($genericNames);

        if ($genericArtists->count() > 1) {
            return true;
        }

        return $genericArtists->contains(fn (Artist $artist): bool => preg_match('/\s*&\s*|\s+(?:and|и)\s+/iu', $artist->name) === 1);
    }

    /** @return string[] */
    private function flattenArtistCreditNames(mixed $value): array
    {
        if (is_string($value)) {
            $value = GeniusNameMatcher::storageValue($value);

            return $value !== '' ? [$value] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        if (is_string($value['name'] ?? null)) {
            $name = GeniusNameMatcher::storageValue($value['name']);

            return $name !== '' ? [$name] : [];
        }

        $names = [];
        foreach ($value as $item) {
            array_push($names, ...$this->flattenArtistCreditNames($item));
        }

        return $names;
    }

    /**
     * @param  string[]  $creditNames
     * @return array{0: string[], 1: string[]}
     */
    private function splitGenericArtistCreditNames(array $creditNames): array
    {
        $primary = [];
        $featured = [];

        foreach ($creditNames as $creditName) {
            $creditName = GeniusNameMatcher::storageValue($creditName);

            if ($creditName === '') {
                continue;
            }

            if (preg_match('/\b(?:feat(?:uring)?|ft)\.?\s+/iu', $creditName, $match, PREG_OFFSET_CAPTURE) === 1) {
                $markerOffset = (int) $match[0][1];
                $primaryPart = GeniusNameMatcher::storageValue(substr($creditName, 0, $markerOffset));
                $featuredPart = GeniusNameMatcher::storageValue(substr($creditName, $markerOffset + strlen((string) $match[0][0])));

                if ($primaryPart !== '') {
                    $primary[] = $primaryPart;
                }
                if ($featuredPart !== '') {
                    $featured[] = $featuredPart;
                }

                continue;
            }

            $primary[] = $creditName;
        }

        return [$primary, $featured];
    }

    /**
     * @param  string[]  $creditNames
     * @return EloquentCollection<int, Artist>
     */
    private function artistsFromCreditNames(array $creditNames): EloquentCollection
    {
        $artists = [];

        foreach (array_values(array_unique($creditNames)) as $creditName) {
            foreach (preg_split('/\s*[,;]+\s*/u', GeniusNameMatcher::storageValue($creditName), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
                $artist = $this->existingArtistByCreditName($segment);

                if ($artist) {
                    $artists[$artist->id] = $artist;
                    continue;
                }

                foreach (GeniusNameMatcher::splitArtistCredits($segment) as $splitName) {
                    $artist = $this->existingArtistByCreditName($splitName);

                    if ($artist) {
                        $artists[$artist->id] = $artist;
                    }
                }
            }
        }

        return new EloquentCollection(array_values($artists));
    }

    private function existingArtistByCreditName(string $name): ?Artist
    {
        $name = GeniusNameMatcher::storageValue($name);

        if ($name === '') {
            return null;
        }

        $normalizedName = str_replace('ё', 'е', mb_strtolower($name, 'UTF-8'));

        if (array_key_exists($normalizedName, $this->artistCreditNameCache)) {
            return $this->artistCreditNameCache[$normalizedName];
        }

        $artist = Artist::query()->where('name', $name)->first();

        if (! $artist) {
            $artist = Artist::query()
                ->whereRaw("LOWER(REPLACE(name, 'ё', 'е')) = ?", [$normalizedName])
                ->first();
        }

        $this->artistCreditNameCache[$normalizedName] = $artist;

        return $artist;
    }

    /**
     * Genius may expose a collective act and repeat the same act as each member
     * in its flattened names field. Keep the known collective credit and drop
     * any of its members repeated in the same primary-credit set. Separate
     * featured credits are handled independently.
     *
     * @param  EloquentCollection<int, Artist>  $artists
     * @return EloquentCollection<int, Artist>
     */
    private function collapseExpandedCollectiveCredits(EloquentCollection $artists): EloquentCollection
    {
        $artists = new EloquentCollection($artists->unique('id')->values()->all());
        $artistIds = $artists->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $memberIdsToCollapse = [];

        foreach ($artists as $artist) {
            if (preg_match('/\s*&\s*|\s+(?:and|и)\s+/iu', $artist->name) !== 1) {
                continue;
            }

            $memberArtists = collect(GeniusNameMatcher::splitArtistCredits($artist->name))
                ->map(fn (string $name): ?Artist => $this->existingArtistByCreditName($name))
                ->filter()
                ->unique('id')
                ->values();

            if ($memberArtists->count() >= 2) {
                foreach ($memberArtists->filter(fn (Artist $member): bool => in_array((int) $member->id, $artistIds, true)) as $member) {
                    $memberIdsToCollapse[] = (int) $member->id;
                }
            }
        }

        if ($memberIdsToCollapse === []) {
            return $artists;
        }

        return new EloquentCollection(
            $artists
                ->reject(fn (Artist $artist): bool => in_array((int) $artist->id, $memberIdsToCollapse, true))
                ->values()
                ->all()
        );
    }

    private function songMatchesArtistPage(array $songSummary, ParsedArtistPage $page): bool
    {
        $artistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        return $this->songPrimaryArtistMatchesPage($songSummary, $artistReferenceNames)
            || $this->songContainsArtist($songSummary, $artistReferenceNames);
    }

    private function songTitleMatchesParsedTrack(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        $parsedTitle = GeniusNameMatcher::canonicalTrack($parsedTrack->title);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));
        $parsedFlags = $this->versionFlags($parsedTrack->title);
        $songFlags = $this->versionFlags((string) ($songPayload['title'] ?? ''));

        if ($parsedTitle === '' || $songTitle === '') {
            return false;
        }

        if (in_array('ringtone', $parsedFlags, true) && ! in_array('ringtone', $songFlags, true)) {
            return false;
        }

        if ($this->namedVersionQualifierMissing($parsedTrack->title, (string) ($songPayload['title'] ?? ''))) {
            return false;
        }

        $score = GeniusNameMatcher::score($parsedTitle, $songTitle);
        $versionScore = $this->versionMatchAdjustment($parsedTrack->title, (string) ($songPayload['title'] ?? ''));

        if ($score >= 0.94) {
            return $versionScore >= -0.18;
        }

        if ($score >= 0.88) {
            return $versionScore >= -0.06;
        }

        return $score >= 0.82 && $versionScore >= 0.0;
    }

    private function namedVersionQualifierMissing(string $sourceTitle, string $geniusTitle): bool
    {
        preg_match_all('/[\(\[]([^\)\]]*\b(?:remix|remiks|ремикс|mix|miks|микс|extended|edit|live|acoustic|instrumental)\b[^\)\]]*)[\)\]]/iu', $sourceTitle, $matches);
        $qualifiers = $matches[1] ?? [];

        if (preg_match('/\s[-–—]\s([^\r\n]*\b(?:remix|remiks|ремикс|mix|miks|микс|extended|edit|live|acoustic|instrumental)\b[^\r\n]*)$/iu', $sourceTitle, $suffix) === 1) {
            $qualifiers[] = $suffix[1];
        }

        $geniusTitle = GeniusNameMatcher::normalizeLoose($geniusTitle);

        foreach ($qualifiers as $sourceQualifier) {
            $qualifier = GeniusNameMatcher::normalizeLoose((string) $sourceQualifier);
            $qualifier = preg_replace('/\b(?:remix|remiks|mix|miks|extended|radio|club|edit|version|live|acoustic|instrumental)\b/u', ' ', $qualifier) ?? $qualifier;
            $qualifier = trim(preg_replace('/\s+/u', ' ', $qualifier) ?? $qualifier);

            foreach (explode(' ', $qualifier) as $word) {
                if ((mb_strlen($word) >= 3 || ctype_digit($word))
                    && ! str_contains(' ' . $geniusTitle . ' ', ' ' . $word . ' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertPrimaryArtistFromMuzofond(ParsedArtistPage $page): array
    {
        $storedName = $this->preferredPageArtistStorageName($page);
        $baseSlug = Str::slug($storedName) ?: 'artist';

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
            ->first();

        $created = false;

        if (! $artist) {
            $artist = Artist::create([
                'name' => $storedName,
                'slug' => $this->uniqueArtistSlug($baseSlug),
                'image_url' => ArtworkUrl::usable($page->imageUrl),
                'description_preview' => null,
                'genius_id' => null,
            ]);

            $created = true;
        } else {
            $artist->forceFill([
                'name' => $storedName,
                'slug' => $this->uniqueArtistSlugForExisting($baseSlug, $artist->id),
                'image_url' => ArtworkUrl::firstUsable($artist->image_url, $page->imageUrl),
            ])->save();
        }

        return [$artist, $created];
    }

    /**
     * @return array{0: Artist, 1: bool}
     */
    private function upsertArtistFromGenius(?Artist $seedArtist, array $payload): array
    {
        $storedName = GeniusNameMatcher::storageValue((string) ($payload['name'] ?? ''));
        $geniusId = (int) ($payload['id'] ?? 0);
        $baseSlug = Str::slug($storedName) ?: 'artist';
        $artist = Artist::query()
            ->where('genius_id', $geniusId)
            ->first();

        $created = false;

        if (! $artist && $seedArtist && $this->canUseSeedArtistForPayload($seedArtist, $payload)) {
            $artist = $seedArtist;
        }

        if (! $artist && $storedName !== '') {
            $artist = $this->findUnclaimedArtistByGeniusName($storedName, $geniusId);
        }

        if (! $artist) {
            $created = true;
            $artist = new Artist();
            $artist->slug = $this->uniqueArtistSlug($baseSlug);
        } elseif (! $artist->slug) {
            $artist->slug = $this->uniqueArtistSlug($baseSlug);
        }

        $artist->slug = $this->uniqueArtistSlugForExisting($baseSlug, $artist->id);

        $artistPayload = GeniusNameMatcher::sanitizeForDatabase([
            'name' => $storedName,
            'image_url' => ArtworkUrl::firstUsable(
                $this->geniusClient->artistImageUrl($payload),
                $seedArtist?->image_url,
                $artist->image_url,
            ),
            'description_preview' => GeniusNameMatcher::cleanDescriptionPreview($payload['description_preview'] ?? null),
            'genius_id' => $geniusId,
        ]);

        $artist->forceFill($artistPayload)->save();
        $this->artistCreditNameCache[str_replace('ё', 'е', mb_strtolower($storedName, 'UTF-8'))] = $artist;

        return [$artist, $created];
    }


    private function canUseSeedArtistForPayload(Artist $seedArtist, array $payload): bool
    {
        $payloadId = (int) ($payload['id'] ?? 0);
        $seedId = (int) ($seedArtist->genius_id ?? 0);

        // A Genius ID is the artist identity. Never let a similar display name
        // repurpose a seed row that is already bound to another Genius artist.
        if ($seedId > 0) {
            return $payloadId > 0 && $seedId === $payloadId;
        }

        return $this->artistNameMatchesExactly(
            $seedArtist->name,
            [(string) ($payload['name'] ?? '')],
        );
    }

    /**
     * Reuse a local row by exact normalized name only while it has no conflicting
     * Genius identity. Rows bound to another Genius ID must remain separate even
     * if their display names or aliases match.
     */
    private function findUnclaimedArtistByGeniusName(string $name, int $geniusId): ?Artist
    {
        $name = GeniusNameMatcher::storageValue($name);
        $normalizedName = $this->normalizeArtistIdentityName($name);

        if ($normalizedName === '') {
            return null;
        }

        return Artist::query()
            ->whereRaw("LOWER(REPLACE(name, 'ё', 'е')) = ?", [$normalizedName])
            ->where(function ($query) use ($geniusId): void {
                $query->whereNull('genius_id');

                if ($geniusId > 0) {
                    $query->orWhere('genius_id', $geniusId);
                }
            })
            ->first();
    }

    /**
     * @param  string[]  $names
     */
    private function artistNameMatchesExactly(string $name, array $names): bool
    {
        $normalizedName = $this->normalizeArtistIdentityName($name);

        if ($normalizedName === '') {
            return false;
        }

        foreach ($names as $candidateName) {
            if ($normalizedName === $this->normalizeArtistIdentityName($candidateName)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeArtistIdentityName(string $name): string
    {
        return str_replace(
            'ё',
            'е',
            mb_strtolower(GeniusNameMatcher::storageValue($name), 'UTF-8'),
        );
    }


    private function shouldCreateAlbumFromPayload(array $payload): bool
    {
        $artistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($artistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $artistPayloads = collect([$payload['artist']]);
        }

        if ($artistPayloads->isEmpty()) {
            return true;
        }

        $artistNames = $artistPayloads
            ->map(fn ($artistPayload) => GeniusNameMatcher::storageValue((string) ($artistPayload['name'] ?? '')))
            ->filter()
            ->values();

        if ($artistNames->isEmpty()) {
            return true;
        }

        return $artistNames->contains(fn (string $artistName) => ! $this->isVariousArtistsName($artistName));
    }

    private function rejectVariousArtists(EloquentCollection $artists): EloquentCollection
    {
        if ($artists->count() <= 1) {
            return $artists;
        }

        $filtered = $artists
            ->filter(fn (mixed $artist) => $artist instanceof Artist)
            ->reject(fn (Artist $artist) => $this->isVariousArtistsName($artist->name))
            ->values();

        return $filtered->isNotEmpty()
            ? new EloquentCollection($filtered->all())
            : $artists;
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumSummaries
     */
    private function syncArtistAlbums(Artist $artist, array $albumSummaries): int
    {
        foreach ($albumSummaries as $albumSummary) {
            $albumTitle = $this->storedAlbumTitle((string) ($albumSummary['name'] ?? $albumSummary['title'] ?? ''));

            if ($albumTitle === '') {
                continue;
            }

            $existingAlbum = $this->findBestLocalFallbackAlbumMatch($artist, $albumTitle);

            if (! $existingAlbum instanceof Album) {
                continue;
            }

            $albumDetail = $this->shouldCreateAlbumFromPayload($albumSummary)
                ? $albumSummary
                : ($this->geniusClient->album((int) $albumSummary['id']) ?? $albumSummary);

            if (! $this->shouldCreateAlbumFromPayload($albumDetail)) {
                continue;
            }

            $this->refreshExistingAlbumFromGenius($existingAlbum, $artist, $albumDetail);
        }

        return 0;
    }

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbumFromGenius(Artist $fallbackArtist, array $payload, ?string $muzofondCoverImageUrl = null): array
    {
        $album = Album::query()
            ->where('genius_id', (int) $payload['id'])
            ->first();

        $created = false;
        $albumTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        if (! $album) {
            $album = Album::query()
                ->where('artist_id', $fallbackArtist->id)
                ->whereRaw('LOWER(title) = ?', [Str::lower($albumTitle)])
                ->first();
        }

        $albumArtistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($albumArtistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $albumArtistPayloads = collect([$payload['artist']]);
        }

        $albumArtists = new EloquentCollection();

        foreach ($albumArtistPayloads as $index => $artistPayload) {
            [$albumArtist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);

            if ($this->isVariousArtistsName($albumArtist->name)) {
                continue;
            }

            $albumArtists->push($albumArtist);
        }

        if ($albumArtists->isEmpty()) {
            if ($album && method_exists($album, 'artists')) {
                $album->loadMissing('artists');

                if ($album->artists->isNotEmpty()) {
                    $albumArtists = new EloquentCollection($album->artists->all());
                }
            }

            if ($albumArtists->isEmpty()) {
                $albumArtists->push($fallbackArtist);
            }
        }

        $albumArtists = $this->rejectVariousArtists(new EloquentCollection(
            $albumArtists
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        /** @var Artist $primaryAlbumArtist */
        $primaryAlbumArtist = $this->resolvePreferredAlbumArtist($fallbackArtist, $albumArtists);
        $previousArtistId = $album?->artist_id;

        if (! $album) {
            $created = true;
            $album = new Album();
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($albumTitle)
                    ?: 'album'
            );
        } elseif (! $album->slug) {
            $album->slug = $this->uniqueAlbumSlug(
                Str::slug($albumTitle)
                    ?: 'album'
            );
        }

        $albumPayload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryAlbumArtist->id,
            'title' => $albumTitle,
            'cover_image_url' => ArtworkUrl::firstUsable(
                $payload['cover_art_thumbnail_url'] ?? null,
                $payload['cover_art_url'] ?? null,
                $payload['image_url'] ?? null,
                $muzofondCoverImageUrl,
                $album->cover_image_url,
            ),
            'release_date' => $this->completeAlbumPayloadReleaseDate($payload)
                ?? $album->release_date?->toDateString(),
            'release_date_components' => is_array($payload['release_date_components'] ?? null)
                ? $payload['release_date_components']
                : $album->release_date_components,
            'genius_id' => (int) $payload['id'],
        ]);

        try {
            $album->forceFill($albumPayload)->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception, 'albums_genius_id_unique') || (int) ($albumPayload['genius_id'] ?? 0) <= 0) {
                throw $exception;
            }

            $existingAlbum = Album::query()
                ->where('genius_id', (int) $albumPayload['genius_id'])
                ->first();

            if (! $existingAlbum instanceof Album) {
                throw $exception;
            }

            $album = $existingAlbum;
            $created = false;
            $album->forceFill($albumPayload)->save();
        }

        $this->rememberLocalAlbum($album, $previousArtistId);

        if (method_exists($album, 'artists')) {
            $album->artists()->sync($albumArtists->pluck('id')->unique()->values()->all());
        }

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrackFromGenius(Artist $fallbackArtist, ParsedTrack $parsedTrack, array $songPayload): array
    {
        $resolvedNameCredits = $this->resolveNameOnlyTrackCredits($songPayload);
        $primaryPerformerPayloads = collect($this->geniusArtistPayloadList($songPayload['primary_artists'] ?? null))
            ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
            ->values();

        if ($primaryPerformerPayloads->isEmpty() && is_array($songPayload['primary_artist'] ?? null) && isset($songPayload['primary_artist']['id'])) {
            $primaryPerformerPayloads = collect([$songPayload['primary_artist']]);
        }

        $featuredPerformerPayloads = collect($this->geniusArtistPayloadList($songPayload['featured_artists'] ?? null))
            ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
            ->values();

        $primaryIdPerformers = new EloquentCollection();
        $featuredIdPerformers = new EloquentCollection();

        foreach ($primaryPerformerPayloads as $index => $artistPayload) {
            [$artist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);
            $primaryIdPerformers->push($artist);
        }

        foreach ($featuredPerformerPayloads as $artistPayload) {
            [$artist] = $this->upsertArtistFromGenius(null, $artistPayload);
            $featuredIdPerformers->push($artist);
        }

        $featuredPerformers = new EloquentCollection(
            collect($resolvedNameCredits['featured'])
                ->concat($featuredIdPerformers)
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        );

        $featuredIds = $featuredPerformers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $primaryPerformers = new EloquentCollection(
            collect($resolvedNameCredits['primary'])
                ->reject(fn (Artist $artist): bool => in_array((int) $artist->id, $featuredIds, true))
                ->concat($primaryIdPerformers)
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        );

        $track = $this->findTrackByAudioIdentity($parsedTrack);

        if ($track instanceof Track && ! $this->hasCompleteGeniusArtistCredits($songPayload)) {
            $existingArtistCredits = $track->artists()
                ->get()
                ->sortBy(fn (Artist $artist): array => [
                    ($artist->pivot?->credit_role ?? 'primary') === 'featured' ? 1 : 0,
                    (int) ($artist->pivot?->credit_position ?? 0),
                ])
                ->values();
            $existingPrimaryCredits = $existingArtistCredits
                ->filter(fn (Artist $artist): bool => ($artist->pivot?->credit_role ?? 'primary') !== 'featured')
                ->values();
            $existingFeaturedCredits = $existingArtistCredits
                ->filter(fn (Artist $artist): bool => ($artist->pivot?->credit_role ?? 'primary') === 'featured')
                ->values();

            if ($existingPrimaryCredits->isEmpty() && $track->artist instanceof Artist) {
                $existingPrimaryCredits->push($track->artist);
            }

            $primaryPerformers = new EloquentCollection(
                $existingPrimaryCredits
                    ->concat($primaryPerformers)
                    ->unique(fn (Artist $artist) => $artist->id)
                    ->values()
                    ->all()
            );
            $featuredPerformers = new EloquentCollection(
                $existingFeaturedCredits
                    ->concat($featuredPerformers)
                    ->unique(fn (Artist $artist) => $artist->id)
                    ->values()
                    ->all()
            );
        }

        $primaryPerformers = $this->collapseExpandedCollectiveCredits($primaryPerformers);
        $primaryPerformers = $this->rejectVariousArtists(new EloquentCollection(
            $primaryPerformers
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        $featuredPerformers = $this->rejectVariousArtists(new EloquentCollection(
            $featuredPerformers
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        $performers = $this->rejectVariousArtists(new EloquentCollection(
            collect($primaryPerformers)
                ->concat($featuredPerformers)
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));

        if ($performers->isEmpty()) {
            $performers->push($fallbackArtist);
        }

        $primaryArtist = $this->resolveTrackPrimaryArtist($fallbackArtist, $primaryPerformers, $performers);

        if (! $performers->contains(fn (Artist $artist) => $artist->id === $primaryArtist->id)) {
            $performers->prepend($primaryArtist);
        }

        if ($this->debugMatchingEnabled()) {
            $this->matchingLog('debug', 'Genius track artist credits resolved for persistence.', [
                'genius_song_id' => (int) ($songPayload['id'] ?? 0),
                'primary_payload_artist_ids' => $primaryPerformerPayloads
                    ->map(fn (array $artist): array => [
                        'id' => (int) ($artist['id'] ?? 0),
                        'name' => GeniusNameMatcher::storageValue((string) ($artist['name'] ?? '')),
                    ])
                    ->values()
                    ->all(),
                'featured_payload_artist_ids' => $featuredPerformerPayloads
                    ->map(fn (array $artist): array => [
                        'id' => (int) ($artist['id'] ?? 0),
                        'name' => GeniusNameMatcher::storageValue((string) ($artist['name'] ?? '')),
                    ])
                    ->values()
                    ->all(),
                'resolved_primary_artists' => $primaryPerformers
                    ->map(fn (Artist $artist): array => ['id' => (int) $artist->id, 'name' => $artist->name])
                    ->values()
                    ->all(),
                'resolved_featured_artists' => $featuredPerformers
                    ->map(fn (Artist $artist): array => ['id' => (int) $artist->id, 'name' => $artist->name])
                    ->values()
                    ->all(),
                'name_credit_sources' => array_keys((array) ($songPayload['_wf_credit_names'] ?? [])),
            ]);
        }

        $album = $this->resolveMatchedTrackAlbum($fallbackArtist, $primaryArtist, $parsedTrack, $songPayload);
        $blockedAlbumId = null;

        if ($album instanceof Album && $this->artistListTrackConflictsWithAlbumPageOccurrence(
            $album,
            $parsedTrack,
            $primaryArtist,
            $track,
            (int) ($songPayload['id'] ?? 0),
        )) {
            $blockedAlbumId = (int) $album->id;
            $album = null;
        }

        $albumId = $album?->id;

        $geniusTitle = GeniusNameMatcher::normalizeStoredTrackTitle((string) ($songPayload['title'] ?? $parsedTrack->title));
        $resolvedTrackNumber = $this->resolveTrackNumber($parsedTrack, $songPayload, $album);

        $created = false;

        if (! $track) {
            $created = true;
            $track = new Track();
        }

        $genres = GeniusNameMatcher::extractGenres((array) ($songPayload['tags'] ?? []));

        $payload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => $geniusTitle,
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
            'release_year' => $this->resolveReleaseYear($songPayload, $parsedTrack),
            'track_number' => $resolvedTrackNumber
                ?? ($albumId !== null && (int) ($track?->album_id ?? 0) === (int) $albumId ? $track?->track_number : null),
            'genres' => $genres !== [] ? $genres : ($parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null),
            'language' => GeniusNameMatcher::resolveLanguageCode((array) ($songPayload['tags'] ?? []), $songPayload['language'] ?? null),
            'description_preview' => GeniusNameMatcher::cleanDescriptionPreview($songPayload['description_preview'] ?? null),
            'genius_url' => $songPayload['url'] ?? null,
            'cover_image_url' => $album ? null : ArtworkUrl::firstUsable(
                $songPayload['song_art_image_url'] ?? null,
                $songPayload['header_image_url'] ?? null,
                $parsedTrack->albumCoverImageUrl,
                $track?->cover_image_url,
            ),
            'genius_id' => (int) $songPayload['id'],
            'genius_pageviews' => max(0, (int) floor(((int) data_get($songPayload, 'stats.pageviews', 0)) / 20)),
        ]);

        $audioFileId = MuzofondAudioIdentity::fileIdFromUrl($parsedTrack->audioUrl, $parsedTrack->audioFileId);

        if ($audioFileId !== null && $this->trackHasColumn('muzofond_audio_id')) {
            $payload['muzofond_audio_id'] = $audioFileId;
        }

        if ($this->trackHasColumn('muzofond_title')) {
            $payload['muzofond_title'] = GeniusNameMatcher::storageValue($parsedTrack->title);
            if ($parsedTrack->albumTitle !== null) {
                $payload['muzofond_album_title'] = $this->storedAlbumTitle($parsedTrack->albumTitle);
            }
        }

        if ($this->trackHasColumn('muzofond_album_appearances') && $parsedTrack->albumAppearances !== []) {
            $payload['muzofond_album_appearances'] = $this->mergedMuzofondAlbumAppearances($track, $parsedTrack);
        }

        if ($this->trackHasColumn('audio_version_ambiguous')) {
            $payload['audio_version_ambiguous'] = false;
        }

        $songGeniusId = (int) ($payload['genius_id'] ?? 0);
        $existingOccurrenceOwner = $songGeniusId > 0
            ? $this->findExistingOccurrenceOwner($songGeniusId, $primaryArtist, $parsedTrack, $album, $track, $resolvedTrackNumber)
            : null;

        if ($existingOccurrenceOwner instanceof Track
            && (! $track instanceof Track || (int) $existingOccurrenceOwner->id !== (int) $track->id)) {
            $owner = $existingOccurrenceOwner;
            $incomingPreferred = $this->incomingAlbumSourceIsPreferred($owner, $parsedTrack, $album);
            $alternateSources = (array) ($owner->muzofond_alternate_sources ?? []);
            $this->appendAlternateSource($alternateSources, $this->alternateSourceFromParsedTrack(
                $parsedTrack,
                $songPayload,
                $album,
                $resolvedTrackNumber,
            ));

            if ($track instanceof Track && $track->exists) {
                foreach ((array) ($track->muzofond_alternate_sources ?? []) as $alternateSource) {
                    if (is_array($alternateSource)) {
                        $this->appendAlternateSource($alternateSources, $alternateSource);
                    }
                }
                $this->appendAlternateSource($alternateSources, $this->alternateSourceFromTrack($track));
                if ($this->trackHasColumn('muzofond_alternate_sources')) {
                    $owner->muzofond_alternate_sources = $alternateSources;
                    $owner->save();
                }
                $this->mergeDuplicateTrackRelations($owner, $track);
                $this->mergeStoredAlbumAppearances($owner, (array) ($track->muzofond_album_appearances ?? []));
            }

            if (! $this->sameMuzofondAudioSource($owner, $parsedTrack)) {
                $this->appendAlternateSource($alternateSources, $this->alternateSourceFromTrack($owner));
            }

            $payload['muzofond_alternate_sources'] = $alternateSources;
            $payload['muzofond_album_appearances'] = $this->mergeAlbumAppearanceLists(
                (array) ($owner->muzofond_album_appearances ?? []),
                (array) ($payload['muzofond_album_appearances'] ?? []),
                $parsedTrack->albumAppearances,
            );

            if ($incomingPreferred) {
                $payload['muzofond_audio_id'] = $audioFileId;
                $payload['muzofond_title'] = GeniusNameMatcher::storageValue($parsedTrack->title);
                $payload['muzofond_album_title'] = $this->storedAlbumTitle($parsedTrack->albumTitle ?? '');
                $payload['original_link'] = $parsedTrack->audioUrl;
                if (! (bool) $owner->is_downloaded) {
                    $payload['audio_url'] = $parsedTrack->audioUrl;
                }
            } else {
                $payload['artist_id'] = $primaryArtist->id;
                $payload['album_id'] = $owner->album_id;
                $payload['audio_url'] = $owner->audio_url;
                $payload['original_link'] = $owner->original_link ?: $owner->audio_url;
                $payload['muzofond_audio_id'] = $owner->muzofond_audio_id;
                $payload['muzofond_title'] = $owner->muzofond_title;
                $payload['muzofond_album_title'] = $owner->muzofond_album_title;
            }

            $track = $owner;
            $created = false;
        }

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        if (! $this->trackHasColumn('genius_pageviews')) {
            unset($payload['genius_pageviews']);
        }

        $payload = $this->preserveExistingAudioFields($track, $payload);

        $existingAlbumId = (int) ($track->album_id ?? 0);
        if ($blockedAlbumId !== null
            && $track->exists
            && $existingAlbumId > 0
            && $existingAlbumId !== $blockedAlbumId) {
            $payload['album_id'] = $existingAlbumId;
            $payload['track_number'] = $track->track_number;
        }

        $effectiveGeniusId = (int) ($payload['genius_id'] ?? 0);
        if ($album instanceof Album
            && $effectiveGeniusId > 0
            && $this->albumHasOtherTrackWithGeniusId($album, $effectiveGeniusId, $track)) {
            $blockedAlbumId = (int) $album->id;
            $existingAlbumId = (int) ($track->album_id ?? 0);
            $album = null;
            $albumId = null;
            $payload['album_id'] = $existingAlbumId > 0 && $existingAlbumId !== $blockedAlbumId
                ? $existingAlbumId
                : null;
            $payload['track_number'] = $payload['album_id'] !== null ? $track->track_number : null;
        }

        $track->forceFill($payload)->save();

        $artistCredits = [];

        foreach ($primaryPerformers as $position => $performer) {
            $artistCredits[$performer->id] = [
                'credit_role' => 'primary',
                'credit_position' => $position,
            ];
        }

        foreach ($featuredPerformers as $position => $performer) {
            if (! isset($artistCredits[$performer->id])) {
                $artistCredits[$performer->id] = [
                    'credit_role' => 'featured',
                    'credit_position' => $position,
                ];
            }
        }

        $artistCredits[$primaryArtist->id] ??= [
            'credit_role' => 'primary',
            'credit_position' => 0,
        ];

        $track->artists()->sync($artistCredits);
        $this->syncTrackAlbumMemberships(
            $track,
            $album,
            $primaryArtist,
            $parsedTrack,
            $songPayload,
        );

        return [$track, $created];
    }

    private function findExistingOccurrenceOwner(
        ?int $songGeniusId,
        Artist $primaryArtist,
        ParsedTrack $source,
        ?Album $album,
        ?Track $currentTrack,
        ?int $resolvedTrackNumber = null,
    ): ?Track {
        $sourceAlbumTitle = $this->parsedSourceAlbumTitle($source);
        $query = Track::query()
            ->where('artist_id', $primaryArtist->id)
            ->where('duration_seconds', $source->durationSeconds)
            ->where(function ($query) use ($songGeniusId, $album, $sourceAlbumTitle): void {
                $query->where(function ($geniusQuery) use ($songGeniusId): void {
                    if ($songGeniusId !== null && $songGeniusId > 0) {
                        $geniusQuery->where('genius_id', $songGeniusId);
                    } else {
                        $geniusQuery->whereRaw('1 = 0');
                    }
                });

                $query->orWhere(function ($sourceQuery) use ($songGeniusId, $album, $sourceAlbumTitle): void {
                    if ($songGeniusId !== null && $songGeniusId > 0) {
                        $sourceQuery->whereNull('genius_id');
                    }

                    if ($album instanceof Album || $sourceAlbumTitle !== null) {
                        $sourceQuery->where(function ($contextQuery) use ($album, $sourceAlbumTitle): void {
                            if ($album instanceof Album) {
                                $contextQuery->where('album_id', $album->id)
                                    ->orWhereHas('albums', fn ($linkedAlbumQuery) => $linkedAlbumQuery->where('albums.id', $album->id));
                            }

                            if ($sourceAlbumTitle !== null) {
                                $contextQuery->orWhereRaw('LOWER(muzofond_album_title) = ?', [mb_strtolower($sourceAlbumTitle)]);
                            }
                        });
                    } else {
                        $sourceQuery->whereNull('album_id')->whereDoesntHave('albums');
                    }
                });
            })
            ->with(['album', 'artists', 'albums']);

        if ($songGeniusId !== null && $songGeniusId > 0) {
            $query->orderByRaw('CASE WHEN genius_id = ? THEN 0 ELSE 1 END', [$songGeniusId]);
        }

        if ($album instanceof Album) {
            $query->orderByRaw(
                'CASE WHEN tracks.album_id = ? OR EXISTS (SELECT 1 FROM album_track WHERE album_track.album_id = ? AND album_track.track_id = tracks.id) THEN 0 ELSE 1 END',
                [$album->id, $album->id],
            );
        }

        $query->orderBy('id');

        if ($currentTrack instanceof Track && $currentTrack->exists) {
            $query->whereKeyNot($currentTrack->id);
        }

        $matches = $query->get()->filter(fn (Track $candidate): bool =>
            ($songGeniusId === null
                || (int) ($candidate->genius_id ?? 0) === 0
                || (int) $candidate->genius_id === $songGeniusId)
            && $this->canConsolidateMatchedSource($candidate, $source, $primaryArtist, $album, $resolvedTrackNumber)
        )->values();

        $incomingPosition = $album instanceof Album
            ? $this->incomingTrackPositionForAlbum($source, $album, $resolvedTrackNumber)
            : $this->parsedTrackOccurrenceContext($source)[1];

        if ($matches->count() > 1 && $incomingPosition === null) {
            // An artist-page row has no slot number. If multiple same-song rows
            // already occupy this release, assigning it to the first one would
            // silently collapse a separate album occurrence.
            return null;
        }

        return $matches->first();
    }

    private function canConsolidateMatchedSource(
        Track $owner,
        ParsedTrack $source,
        Artist $primaryArtist,
        ?Album $album = null,
        ?int $resolvedTrackNumber = null,
    ): bool
    {
        $ownerTitle = (string) ($owner->muzofond_title ?: $owner->title);

        if (((bool) $owner->audio_version_ambiguous
                || $this->sourceAudioVersionAmbiguous($source))
            || (int) $owner->duration_seconds !== $source->durationSeconds
            || GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($ownerTitle))
                !== GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($source->title))
            || $this->versionFlags($ownerTitle) !== $this->versionFlags($source->title)) {
            return false;
        }

        if ((int) $owner->artist_id !== (int) $primaryArtist->id
            || ! $this->sourceCreditsCanBeConsolidated($owner, $source, $primaryArtist)) {
            return false;
        }

        if ($album instanceof Album) {
            $hasAlbumContext = $this->trackBelongsToAlbum($owner, $album)
                || ($source->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
                    && $this->trackHasExplicitAlbumContext($owner, $album, $primaryArtist));
            $hasNoAlbumContext = $source->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
                && $this->trackHasNoAlbumContext($owner);

            if (! $hasAlbumContext && ! $hasNoAlbumContext) {
                return false;
            }

            $storedPosition = $this->storedSourcePositionForAlbum($owner, $album);
            if ($storedPosition === null && (int) ($owner->album_id ?? 0) === (int) $album->id) {
                $storedPosition = (int) ($owner->track_number ?? 0) > 0 ? (int) $owner->track_number : null;
            }

            $incomingPosition = $this->incomingTrackPositionForAlbum($source, $album, $resolvedTrackNumber);

            return $storedPosition === null || $incomingPosition === null || $storedPosition === $incomingPosition;
        }

        return $this->occurrenceContextsCanConsolidate(
            $this->storedTrackOccurrenceContext($owner),
            $this->parsedTrackOccurrenceContext($source),
        );
    }

    private function trackBelongsToAlbum(Track $track, Album $album): bool
    {
        if ((int) ($track->album_id ?? 0) === (int) $album->id) {
            return true;
        }

        if ($track->relationLoaded('albums')) {
            return $track->albums->contains(fn (Album $linkedAlbum): bool => (int) $linkedAlbum->id === (int) $album->id);
        }

        return $track->albums()->whereKey($album->id)->exists();
    }

    private function trackHasNoAlbumContext(Track $track): bool
    {
        if ((int) ($track->album_id ?? 0) > 0) {
            return false;
        }

        $linkedAlbums = $track->relationLoaded('albums')
            ? $track->albums
            : $track->albums()->get();

        if ($linkedAlbums->isNotEmpty()
            || $this->shouldPersistAlbumTitle($track->muzofond_album_title)) {
            return false;
        }

        return collect((array) ($track->muzofond_album_appearances ?? []))
            ->every(fn ($appearance): bool =>
                ! is_array($appearance)
                || ! $this->shouldPersistAlbumTitle($appearance['title'] ?? null)
            );
    }

    private function albumHasOtherTrackWithGeniusId(
        Album $album,
        int $geniusId,
        ?Track $currentTrack = null,
        ?Track $alsoIgnoreTrack = null,
    ): bool
    {
        if ($geniusId <= 0) {
            return false;
        }

        return Track::query()
            ->where('genius_id', $geniusId)
            ->where(function ($query) use ($album): void {
                $query->where('album_id', $album->id)
                    ->orWhereHas('albums', fn ($albumQuery) => $albumQuery->where('albums.id', $album->id));
            })
            ->when($currentTrack instanceof Track && $currentTrack->exists, fn ($query) => $query->whereKeyNot($currentTrack->id))
            ->when($alsoIgnoreTrack instanceof Track && $alsoIgnoreTrack->exists, fn ($query) => $query->whereKeyNot($alsoIgnoreTrack->id))
            ->exists();
    }

    private function trackHasExplicitAlbumContext(Track $track, Album $album, Artist $artist): bool
    {
        if ((int) ($track->album_id ?? 0) > 0) {
            return false;
        }

        $linkedAlbums = $track->relationLoaded('albums')
            ? $track->albums
            : $track->albums()->get();

        if ($linkedAlbums->isNotEmpty()) {
            return false;
        }

        $storedTitle = $this->shouldPersistAlbumTitle($track->muzofond_album_title)
            ? $this->storedAlbumTitle((string) $track->muzofond_album_title)
            : null;

        if ($storedTitle === null) {
            $pageTitles = collect((array) ($track->muzofond_album_appearances ?? []))
                ->filter(fn ($appearance): bool =>
                    is_array($appearance)
                    && ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                    && $this->shouldPersistAlbumTitle($appearance['title'] ?? null)
                )
                ->map(fn (array $appearance): string => $this->storedAlbumTitle((string) $appearance['title']))
                ->unique(fn (string $title): string => $this->albumComparableLookupKey($title))
                ->values();

            $storedTitle = $pageTitles->count() === 1 ? $pageTitles->first() : null;
        }

        if ($storedTitle === null
            || ! $this->albumTitlesShareIdentity($storedTitle, (string) $album->title)
            || $this->versionFlags($storedTitle) !== $this->versionFlags((string) $album->title)) {
            return false;
        }

        $candidateAlbums = $this->localAlbumsForArtist($artist)->push($album);
        if ((int) $album->artist_id !== (int) $artist->id) {
            $candidateAlbums = $candidateAlbums->merge(
                Album::query()->where('artist_id', $album->artist_id)->get()
            );
        }

        $matchingAlbums = $candidateAlbums
            ->unique(fn (Album $candidate): int => (int) $candidate->id)
            ->filter(fn (Album $candidate): bool =>
                $this->albumTitlesShareIdentity($storedTitle, (string) $candidate->title)
                && $this->versionFlags($storedTitle) === $this->versionFlags((string) $candidate->title)
            );

        return $matchingAlbums->count() === 1
            && (int) $matchingAlbums->first()->id === (int) $album->id;
    }

    private function incomingTrackPositionForAlbum(ParsedTrack $source, Album $album, ?int $resolvedTrackNumber): ?int
    {
        if ($this->albumPageSourceMatchesAlbum($source, $album)
            && (int) ($source->trackNumber ?? 0) > 0) {
            return (int) $source->trackNumber;
        }

        $appearancePosition = $this->parsedSourcePositionForAlbum($source, (string) $album->title);

        if ($appearancePosition !== null) {
            return $appearancePosition;
        }

        return $resolvedTrackNumber !== null && $resolvedTrackNumber > 0 ? $resolvedTrackNumber : null;
    }

    private function albumPageSourceMatchesAlbum(ParsedTrack $source, Album $album): bool
    {
        $albumTitle = $this->parsedSourceAlbumTitle($source);

        return $source->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $albumTitle !== null
            && $this->albumTitlesShareIdentity($albumTitle, (string) $album->title)
            && $this->versionFlags($albumTitle) === $this->versionFlags((string) $album->title);
    }

    private function artistListTrackConflictsWithAlbumPageOccurrence(
        Album $album,
        ParsedTrack $source,
        Artist $primaryArtist,
        ?Track $currentTrack,
        ?int $incomingGeniusId = null,
    ): bool {
        if ($source->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            || $this->sourceAudioVersionAmbiguous($source)
            || $incomingGeniusId === null
            || $incomingGeniusId <= 0) {
            return false;
        }

        $sourceTitle = GeniusNameMatcher::normalizeStoredTrackTitle($source->title);
        $sourceTitleKey = GeniusNameMatcher::normalizeLoose($sourceTitle);
        $sourceVersionFlags = $this->versionFlags($source->title);

        if ($sourceTitleKey === '') {
            return false;
        }

        $members = Track::query()
            ->where(function ($query) use ($album): void {
                $query->where('album_id', $album->id)
                    ->orWhereHas('albums', fn ($albumQuery) => $albumQuery->where('albums.id', $album->id));
            })
            ->when($currentTrack instanceof Track, fn ($query) => $query->whereKeyNot($currentTrack->id))
            ->with(['artists', 'albums'])
            ->get();

        foreach ($members as $member) {
            if ((int) $member->artist_id !== (int) $primaryArtist->id
                || (bool) $member->audio_version_ambiguous
                || (int) $member->duration_seconds === $source->durationSeconds) {
                continue;
            }

            $memberTitle = (string) ($member->muzofond_title ?: $member->title);
            if (GeniusNameMatcher::normalizeLoose(GeniusNameMatcher::normalizeStoredTrackTitle($memberTitle)) !== $sourceTitleKey
                || $this->versionFlags($memberTitle) !== $sourceVersionFlags) {
                continue;
            }

            $memberGeniusId = (int) ($member->genius_id ?? 0);
            if ($memberGeniusId !== $incomingGeniusId) {
                continue;
            }

            $hasAlbumPageSource = collect((array) ($member->muzofond_album_appearances ?? []))
                ->contains(fn ($appearance): bool =>
                    is_array($appearance)
                    && ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                    && $this->albumTitlesShareIdentity(
                        $this->storedAlbumTitle((string) ($appearance['title'] ?? '')),
                        (string) $album->title,
                    )
                );

            if ($hasAlbumPageSource && $this->sourceCreditsCanBeConsolidated($member, $source, $primaryArtist)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{0: ?string, 1: ?int} $stored @param array{0: ?string, 1: ?int} $incoming */
    private function occurrenceContextsCanConsolidate(array $stored, array $incoming): bool
    {
        if ($stored[0] !== $incoming[0]) {
            return false;
        }

        // A missing position means the artist page did not identify the release slot.
        // Only two known, conflicting album positions prove separate occurrences.
        return $stored[1] === null || $incoming[1] === null || $stored[1] === $incoming[1];
    }

    /** @return array{0: ?string, 1: ?int} */
    private function parsedTrackOccurrenceContext(ParsedTrack $source): array
    {
        $albumTitle = $this->parsedSourceAlbumTitle($source);
        $albumKey = $albumTitle !== null
            ? GeniusNameMatcher::normalizeLoose($albumTitle)
            : null;
        $number = null;

        if ($albumKey !== null && $source->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE) {
            $number = $source->trackNumber;
        }

        if ($albumKey !== null && ($number === null || $number <= 0)) {
            $numbers = collect($source->albumAppearances)
                ->filter(fn (array $appearance): bool =>
                    GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) ($appearance['title'] ?? ''))) === $albumKey
                    && (int) ($appearance['track_number'] ?? 0) > 0
                )
                ->pluck('track_number')
                ->map(fn ($value): int => (int) $value)
                ->unique()
                ->values();

            $number = $numbers->count() === 1 ? $numbers->first() : null;
        }

        return [$albumKey !== '' ? $albumKey : null, $number !== null && $number > 0 ? $number : null];
    }

    /** @return array{0: ?string, 1: ?int} */
    private function storedTrackOccurrenceContext(Track $track): array
    {
        $albumTitle = $this->shouldPersistAlbumTitle($track->muzofond_album_title)
            ? $this->storedAlbumTitle((string) $track->muzofond_album_title)
            : null;
        $albumAppearances = (array) ($track->muzofond_album_appearances ?? []);
        $albumPageTitles = collect($albumAppearances)
            ->filter(fn (array $appearance): bool =>
                ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && $this->shouldPersistAlbumTitle($appearance['title'] ?? null)
            )
            ->map(fn (array $appearance): string => $this->storedAlbumTitle((string) $appearance['title']))
            ->unique(fn (string $title): string => GeniusNameMatcher::normalizeLoose($title))
            ->values();

        if ($albumPageTitles->count() === 1) {
            $albumTitle = $albumPageTitles->first();
        }

        if ($albumTitle === null) {
            $albumTitle = $track->relationLoaded('album')
                ? ($track->album?->title)
                : ($track->album()->value('title'));
            $albumTitle = $this->shouldPersistAlbumTitle($albumTitle) ? $this->storedAlbumTitle((string) $albumTitle) : null;
        }

        $albumKey = $albumTitle !== null ? GeniusNameMatcher::normalizeLoose($albumTitle) : null;
        $numbers = collect($albumAppearances)
            ->filter(fn (array $appearance): bool =>
                ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && $albumKey !== null
                && GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) ($appearance['title'] ?? ''))) === $albumKey
                && (int) ($appearance['track_number'] ?? 0) > 0
            )
            ->pluck('track_number')
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $position = $numbers->count() === 1 ? $numbers->first() : null;

        if ($position === null && $albumKey !== null) {
            $contextAlbum = null;

            if ($track->relationLoaded('albums')) {
                $contextAlbum = $track->albums->first(fn (Album $candidate): bool =>
                    GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) $candidate->title)) === $albumKey
                );
            }

            if (! $contextAlbum instanceof Album
                && $track->relationLoaded('album')
                && $track->album instanceof Album
                && GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) $track->album->title)) === $albumKey) {
                $contextAlbum = $track->album;
            }

            if ($contextAlbum instanceof Album) {
                $position = $this->storedSourcePositionForAlbum($track, $contextAlbum);
            }
        }

        return [
            $albumKey !== '' ? $albumKey : null,
            $position,
        ];
    }

    private function parsedSourceAlbumTitle(ParsedTrack $source): ?string
    {
        if ($this->shouldPersistAlbumTitle($source->albumTitle)) {
            return $this->storedAlbumTitle((string) $source->albumTitle);
        }

        $albumPageTitles = collect($source->albumAppearances)
            ->filter(fn (array $appearance): bool =>
                ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && $this->shouldPersistAlbumTitle($appearance['title'] ?? null)
            )
            ->map(fn (array $appearance): string => $this->storedAlbumTitle((string) $appearance['title']))
            ->unique(fn (string $title): string => GeniusNameMatcher::normalizeLoose($title))
            ->values();

        return $albumPageTitles->count() === 1 ? $albumPageTitles->first() : null;
    }

    private function sourceCreditsCanBeConsolidated(Track $owner, ParsedTrack $source, Artist $primaryArtist): bool
    {
        $sourceNames = $source->artistNames !== [] ? $source->artistNames : [$primaryArtist->name];
        $storedNames = $owner->relationLoaded('artists')
            ? $owner->artists->pluck('name')->all()
            : $owner->artists()->pluck('artists.name')->all();

        if ($storedNames === []) {
            return true;
        }

        $normalize = static fn (array $names): array => collect($names)
            ->map(fn ($name): string => GeniusNameMatcher::normalizeLoose((string) $name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalize($sourceNames) === $normalize($storedNames);
    }

    private function incomingAlbumSourceIsPreferred(
        Track $owner,
        ParsedTrack $source,
        ?Album $album,
    ): bool
    {
        if (! $album instanceof Album || ! $this->albumPageSourceMatchesAlbum($source, $album)) {
            return false;
        }

        foreach ((array) ($owner->muzofond_album_appearances ?? []) as $appearance) {
            if (is_array($appearance)
                && ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && $this->albumTitlesShareIdentity(
                    $this->storedAlbumTitle((string) ($appearance['title'] ?? '')),
                    (string) $album->title,
                )
                && $this->versionFlags((string) ($appearance['title'] ?? '')) === $this->versionFlags((string) $album->title)) {
                return false;
            }
        }

        return true;
    }

    private function sameMuzofondAudioSource(Track $track, ParsedTrack $source): bool
    {
        return MuzofondAudioIdentity::matchesParsedTrack($track, $source);
    }

    /** @return array<string, mixed> */
    private function alternateSourceFromParsedTrack(
        ParsedTrack $source,
        ?array $songPayload = null,
        ?Album $resolvedAlbum = null,
        ?int $resolvedTrackNumber = null,
    ): array
    {
        return [
            'audio_file_id' => MuzofondAudioIdentity::fileIdFromUrl($source->audioUrl, $source->audioFileId),
            'audio_url' => $source->audioUrl,
            'title' => GeniusNameMatcher::storageValue($source->title),
            'duration_seconds' => $source->durationSeconds,
            'artist_names' => array_values($source->artistNames),
            'album_title' => $source->albumTitle !== null ? $this->storedAlbumTitle($source->albumTitle) : null,
            'source_type' => $source->sourceType,
            'track_number' => $source->trackNumber,
            'album_appearances' => $source->albumAppearances,
            'resolved_album_id' => $resolvedAlbum?->id,
            'resolved_album_title' => $resolvedAlbum?->title,
            'resolved_track_number' => $resolvedTrackNumber,
            'genius_id' => isset($songPayload['id']) ? (int) $songPayload['id'] : null,
            'genius_url' => is_string($songPayload['url'] ?? null) ? $songPayload['url'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function alternateSourceFromTrack(Track $track): array
    {
        $artistNames = $track->relationLoaded('artists')
            ? $track->artists->pluck('name')->values()->all()
            : $track->artists()->pluck('artists.name')->values()->all();

        return [
            'audio_file_id' => $track->muzofond_audio_id,
            'audio_url' => $track->original_link ?: $track->audio_url,
            'title' => $track->muzofond_title ?: $track->title,
            'duration_seconds' => (int) $track->duration_seconds,
            'artist_names' => $artistNames,
            'album_title' => $track->muzofond_album_title,
            'source_type' => null,
            'track_number' => $track->track_number,
            'album_appearances' => (array) ($track->muzofond_album_appearances ?? []),
            'genius_id' => $track->genius_id !== null ? (int) $track->genius_id : null,
            'genius_url' => $track->genius_url,
        ];
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function appendAlternateSource(array &$sources, array $candidate): void
    {
        $url = MuzofondAudioIdentity::canonicalUrl((string) ($candidate['audio_url'] ?? ''));
        $id = trim((string) ($candidate['audio_file_id'] ?? ''));
        if ($url === '' && $id === '') {
            return;
        }

        foreach ($sources as $source) {
            if (($id !== '' && trim((string) ($source['audio_file_id'] ?? '')) === $id)
                || ($url !== '' && MuzofondAudioIdentity::canonicalUrl((string) ($source['audio_url'] ?? '')) === $url)) {
                return;
            }
        }

        $sources[] = $candidate;
    }

    private function mergeDuplicateTrackRelations(Track $owner, Track $duplicate): void
    {
        if ((int) $owner->id === (int) $duplicate->id) {
            return;
        }

        DB::transaction(function () use ($owner, $duplicate): void {
            $sourceAlbumKeys = collect(array_merge(
                $this->shouldPersistAlbumTitle($duplicate->muzofond_album_title)
                    ? [(string) $duplicate->muzofond_album_title]
                    : [],
                array_map(
                    fn (array $appearance): string => (string) ($appearance['title'] ?? ''),
                    array_filter((array) ($duplicate->muzofond_album_appearances ?? []), 'is_array'),
                ),
            ))
                ->map(fn (string $title): string => GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle($title)))
                ->filter()
                ->unique()
                ->all();

            foreach ($duplicate->albums()->get() as $album) {
                $albumKey = GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) $album->title));

                if (! in_array($albumKey, $sourceAlbumKeys, true)) {
                    continue;
                }

                $geniusId = (int) ($owner->genius_id ?? 0) > 0
                    ? (int) $owner->genius_id
                    : (int) ($duplicate->genius_id ?? 0);
                if ($geniusId > 0 && $this->albumHasOtherTrackWithGeniusId($album, $geniusId, $owner, $duplicate)) {
                    continue;
                }

                $ownerAlreadyLinked = DB::table('album_track')
                    ->where('album_id', $album->id)
                    ->where('track_id', $owner->id)
                    ->exists();

                if (! $ownerAlreadyLinked) {
                    $owner->albums()->attach($album->id, [
                        'is_primary' => (bool) $album->pivot->is_primary,
                        'track_number' => $album->pivot->track_number,
                    ]);
                }
            }

            $duplicateArtistCredits = $duplicate->artists()
                ->get()
                ->mapWithKeys(fn (Artist $artist) => [
                    $artist->id => [
                        'credit_role' => $artist->pivot?->credit_role ?? 'primary',
                        'credit_position' => (int) ($artist->pivot?->credit_position ?? 0),
                    ],
                ])
                ->all();
            $existingOwnerArtistIds = array_fill_keys(
                $owner->artists()->pluck('artists.id')->map(fn ($id) => (int) $id)->all(),
                true,
            );
            $owner->artists()->syncWithoutDetaching(array_diff_key($duplicateArtistCredits, $existingOwnerArtistIds));
            $owner->likedByUsers()->syncWithoutDetaching($duplicate->likedByUsers()->pluck('users.id')->all());
            $duplicate->artists()->detach();
            $duplicate->likedByUsers()->detach();
            $duplicate->delete();
        });
    }

    /** @param array<int, array<string, mixed>> $appearances */
    private function mergeStoredAlbumAppearances(Track $track, array $appearances): void
    {
        $merged = $this->mergeAlbumAppearanceLists((array) ($track->muzofond_album_appearances ?? []), $appearances);
        if ($this->trackHasColumn('muzofond_album_appearances')) {
            $track->muzofond_album_appearances = $merged;
            $track->save();
        }
    }

    /** @return array<int, array{title: string, source_type: string, track_number: ?int}> */
    private function mergeAlbumAppearanceLists(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $appearance) {
                if (! is_array($appearance)) {
                    continue;
                }
                $title = $this->storedAlbumTitle((string) ($appearance['title'] ?? ''));
                $key = GeniusNameMatcher::normalizeLoose($title);
                if ($key === '') {
                    continue;
                }
                $candidate = [
                    'title' => $title,
                    'source_type' => (string) ($appearance['source_type'] ?? ParsedTrack::SOURCE_ARTIST_PAGE),
                    'track_number' => isset($appearance['track_number']) ? (int) $appearance['track_number'] : null,
                ];
                if (! isset($merged[$key])
                    || ($candidate['source_type'] === ParsedTrack::SOURCE_ALBUM_PAGE
                        && $merged[$key]['source_type'] !== ParsedTrack::SOURCE_ALBUM_PAGE)) {
                    $merged[$key] = $candidate;
                }
            }
        }
        return array_values($merged);
    }

    private function albumMembershipHasUniqueVersion(
        Album $album,
        ParsedTrack $parsedTrack,
        ?Track $currentTrack,
        ?int $incomingGeniusId = null,
    ): bool
    {
        $title = GeniusNameMatcher::canonicalTrack($parsedTrack->title);

        if ($title === '' || $this->sourceAudioVersionAmbiguous($parsedTrack)) {
            return true;
        }

        $members = Track::query()
            ->where(function ($query) use ($album): void {
                $query->where('album_id', $album->id)
                    ->orWhereHas('albums', fn ($albumQuery) => $albumQuery->where('albums.id', $album->id));
            })
            ->when($currentTrack instanceof Track, fn ($query) => $query->whereKeyNot($currentTrack->id))
            ->with('album')
            ->get(['id', 'artist_id', 'album_id', 'title', 'muzofond_title', 'muzofond_album_title', 'muzofond_album_appearances', 'duration_seconds', 'track_number', 'genius_id', 'audio_version_ambiguous']);
        $incomingPosition = $this->parsedSourcePositionForAlbum($parsedTrack, (string) $album->title);
        $incomingVersionFlags = $this->versionFlags($parsedTrack->title);

        foreach ($members as $member) {
            $memberTitle = (string) ($member->muzofond_title ?: $member->title);

            if ((int) ($album->artist_id ?? 0) > 0
                && (int) $member->artist_id !== (int) $album->artist_id) {
                continue;
            }

            if ((bool) $member->audio_version_ambiguous
                || GeniusNameMatcher::canonicalTrack($memberTitle) !== $title
                || $this->versionFlags($memberTitle) !== $incomingVersionFlags) {
                continue;
            }

            $memberPosition = $this->storedSourcePositionForAlbum($member, $album);

            if ($incomingPosition !== null && $memberPosition !== null) {
                if ($incomingPosition === $memberPosition) {
                    // One release slot cannot contain two source rows with the same
                    // unversioned title, even when Muzofond reports a 1-2 sec delta.
                    return false;
                }

                continue;
            }

            if ((int) $member->duration_seconds !== $parsedTrack->durationSeconds) {
                continue;
            }

            $memberGeniusId = (int) ($member->genius_id ?? 0);

            if ($incomingGeniusId !== null && $incomingGeniusId > 0
                && $memberGeniusId > 0 && $memberGeniusId !== $incomingGeniusId) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function parsedSourcePositionForAlbum(ParsedTrack $parsedTrack, string $albumTitle): ?int
    {
        $albumKey = GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle($albumTitle));

        if ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            && $this->parsedSourceAlbumTitle($parsedTrack) !== null
            && GeniusNameMatcher::normalizeLoose((string) $this->parsedSourceAlbumTitle($parsedTrack)) === $albumKey
            && (int) ($parsedTrack->trackNumber ?? 0) > 0) {
            return (int) $parsedTrack->trackNumber;
        }

        $numbers = collect($parsedTrack->albumAppearances)
            ->filter(fn (array $appearance): bool =>
                ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) ($appearance['title'] ?? ''))) === $albumKey
                && (int) ($appearance['track_number'] ?? 0) > 0
            )
            ->pluck('track_number')
            ->map(fn ($number): int => (int) $number)
            ->unique()
            ->values();

        return $numbers->count() === 1 ? $numbers->first() : null;
    }

    private function storedSourcePositionForAlbum(Track $track, Album $album): ?int
    {
        $albumKey = GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) $album->title));
        $numbers = collect((array) ($track->muzofond_album_appearances ?? []))
            ->filter(fn (array $appearance): bool =>
                ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                && GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle((string) ($appearance['title'] ?? ''))) === $albumKey
                && (int) ($appearance['track_number'] ?? 0) > 0
            )
            ->pluck('track_number')
            ->map(fn ($number): int => (int) $number)
            ->unique()
            ->values();

        if ($numbers->count() === 1) {
            return $numbers->first();
        }

        $pivotNumber = DB::table('album_track')
            ->where('album_id', $album->id)
            ->where('track_id', $track->id)
            ->value('track_number');

        return $pivotNumber !== null && (int) $pivotNumber > 0 ? (int) $pivotNumber : null;
    }

    private function resolveMatchedTrackAlbum(
        Artist $fallbackArtist,
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
    ): ?Album {
        $albumPayload = is_array($songPayload['album'] ?? null) ? $songPayload['album'] : null;
        $albumId = (int) ($albumPayload['id'] ?? 0);

        if ($albumId > 0) {
            $albumPayload = $this->geniusClient->album($albumId) ?? $albumPayload;
        }

        $sourceAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;
        $geniusAlbumTitle = is_array($albumPayload) ? $this->extractAlbumPayloadTitle($albumPayload) : null;
        $sourceAlbumVersion = $this->albumVersionFamily($sourceAlbumTitle);
        $geniusAlbumVersion = $this->albumVersionFamily($geniusAlbumTitle);

        if ($sourceAlbumTitle !== null && $sourceAlbumVersion !== $geniusAlbumVersion
            && ($sourceAlbumVersion !== null || $geniusAlbumVersion !== null)) {
            $fallbackAlbumId = $this->resolveFallbackAlbumId($fallbackArtist, $parsedTrack);
            $fallbackAlbum = $fallbackAlbumId !== null ? Album::query()->find($fallbackAlbumId) : null;

            return $fallbackAlbum instanceof Album
                ? $this->fillAlbumCoverFromMuzofond($fallbackAlbum, $parsedTrack)
                : null;
        }

        if ($sourceAlbumTitle === null
            && array_intersect(['remix', 'remixes'], $this->versionFlags($parsedTrack->title)) !== []
            && $geniusAlbumTitle !== null
            && $geniusAlbumVersion !== 'remix') {
            return null;
        }

        $preferredAlbumPayload = $this->preferredAlbumPayloadForSong($primaryArtist, $parsedTrack, $songPayload, $albumPayload);

        if (is_array($preferredAlbumPayload)) {
            $albumPayload = $preferredAlbumPayload;
            $albumId = (int) ($albumPayload['id'] ?? 0);
        }

        $payloadAlbumTitle = is_array($albumPayload)
            ? $this->extractAlbumPayloadTitle($albumPayload)
            : $this->extractSongAlbumTitle($songPayload);
        $candidateTitles = collect($this->orderedAlbumCandidateTitles(
            $parsedTrack,
            $payloadAlbumTitle,
        ));
        $contextAlbum = $this->resolveAlbumPageContextAlbum($fallbackArtist, $parsedTrack);

        if ($contextAlbum instanceof Album && (
            $this->shouldUseAlbumPageContextAlbum($contextAlbum, $parsedTrack, $payloadAlbumTitle)
            || $this->shouldAllowAlbumPageContextAlbumOverride($parsedTrack, $songPayload, $contextAlbum, $payloadAlbumTitle)
        )) {
            if ($this->shouldRefreshExistingAlbumFromPayload($contextAlbum, $parsedTrack, $albumPayload)) {
                $this->refreshExistingAlbumFromGenius($contextAlbum, $primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);
            }

            return $this->fillAlbumCoverFromMuzofond($contextAlbum, $parsedTrack);
        }

        foreach ([$primaryArtist, $fallbackArtist] as $albumArtist) {
            foreach ($candidateTitles as $candidateTitle) {
                $exactAlbum = $this->findLocalAlbumByComparableTitle($albumArtist, $candidateTitle);

                if ($exactAlbum instanceof Album) {
                    if (! $this->shouldUseLocalAlbumCandidate($exactAlbum, $parsedTrack, $candidateTitle, $payloadAlbumTitle)) {
                        continue;
                    }

                    if ($this->shouldRefreshExistingAlbumFromPayload($exactAlbum, $parsedTrack, $albumPayload)) {
                        $this->refreshExistingAlbumFromGenius($exactAlbum, $primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);
                    }

                    return $this->fillAlbumCoverFromMuzofond($exactAlbum, $parsedTrack);
                }

                $existingAlbum = $this->findBestLocalFallbackAlbumMatch($albumArtist, $candidateTitle);

                if (! $existingAlbum instanceof Album) {
                    continue;
                }

                if (! $this->shouldUseLocalAlbumCandidate($existingAlbum, $parsedTrack, $candidateTitle, $payloadAlbumTitle)) {
                    continue;
                }

                if ($this->shouldRefreshExistingAlbumFromPayload($existingAlbum, $parsedTrack, $albumPayload)) {
                    $this->refreshExistingAlbumFromGenius($existingAlbum, $primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);
                }

                return $this->fillAlbumCoverFromMuzofond($existingAlbum, $parsedTrack);
            }
        }

        foreach ([$primaryArtist, $fallbackArtist] as $albumArtist) {
            $releaseDateFallbackAlbum = $this->findLocalAlbumByReleaseDateFallback($albumArtist, $albumPayload);

            if (! $releaseDateFallbackAlbum instanceof Album) {
                continue;
            }

            if ($this->shouldRefreshExistingAlbumFromPayload($releaseDateFallbackAlbum, $parsedTrack, $albumPayload)) {
                $this->refreshExistingAlbumFromGenius($releaseDateFallbackAlbum, $primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);
            }

            return $this->fillAlbumCoverFromMuzofond($releaseDateFallbackAlbum, $parsedTrack);
        }

        if ($albumId > 0) {
            $existingByGeniusId = Album::query()
                ->where('genius_id', $albumId)
                ->first();

            if ($existingByGeniusId instanceof Album) {
                if ($this->shouldRefreshExistingAlbumFromPayload($existingByGeniusId, $parsedTrack, $albumPayload)) {
                    $this->refreshExistingAlbumFromGenius($existingByGeniusId, $primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);
                }

                return $this->fillAlbumCoverFromMuzofond($existingByGeniusId, $parsedTrack);
            }
        }

        if (is_array($albumPayload) && $this->shouldCreateAlbumFromPayload($albumPayload)) {
            [$album] = $this->upsertAlbumFromGenius($primaryArtist, $albumPayload, $parsedTrack->albumCoverImageUrl);

            return $this->fillAlbumCoverFromMuzofond($album, $parsedTrack);
        }

        return null;
    }

    private function albumVersionFamily(?string $title): ?string
    {
        if ($title === null || $title === '') {
            return null;
        }

        $flags = $this->versionFlags($title);

        if (array_intersect(['remix', 'remixes'], $flags) !== []) {
            return 'remix';
        }

        foreach (['live', 'acoustic', 'instrumental', 'demo'] as $version) {
            if (in_array($version, $flags, true)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     * @return array<string, mixed>|null
     */
    private function preferredAlbumPayloadForSong(
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): ?array {
        if (! $this->shouldResolvePreferredAlbumPayload($parsedTrack, $songPayload, $currentAlbumPayload)) {
            return null;
        }

        $artistGeniusId = $this->preferredAlbumArtistGeniusId($primaryArtist, $songPayload);

        if ($artistGeniusId <= 0) {
            return null;
        }

        $preferredFromParsedAlbumTitle = $this->preferredAlbumPayloadFromParsedAlbumTitle(
            $artistGeniusId,
            $parsedTrack,
            $songPayload,
            $currentAlbumPayload,
        );

        if (is_array($preferredFromParsedAlbumTitle)) {
            return $preferredFromParsedAlbumTitle;
        }

        $this->primePreferredAlbumPayloadCatalog($artistGeniusId);

        $songId = (int) ($songPayload['id'] ?? 0);

        if ($songId > 0 && is_array($this->preferredAlbumBySongId[$artistGeniusId][$songId] ?? null)) {
            return $this->preferredAlbumBySongId[$artistGeniusId][$songId];
        }

        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($songTitle === '') {
            return null;
        }

        foreach ($this->preferredAlbumCandidatesByTitle[$artistGeniusId][$songTitle] ?? [] as $candidate) {
            $candidateSong = is_array($candidate['song'] ?? null) ? $candidate['song'] : [];
            $candidateTitle = (string) ($candidateSong['title'] ?? '');

            if ($candidateTitle === '') {
                continue;
            }

            if (GeniusNameMatcher::score($songTitle, GeniusNameMatcher::canonicalTrack($candidateTitle)) < 0.94) {
                continue;
            }

            if ($this->versionMatchAdjustment($this->parsedTrackVersionContext($parsedTrack), $candidateTitle) < -0.18) {
                continue;
            }

            return is_array($candidate['album'] ?? null) ? $candidate['album'] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     * @return array<string, mixed>|null
     */
    private function preferredAlbumPayloadFromParsedAlbumTitle(
        int $artistGeniusId,
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): ?array {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return null;
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);

        if ($parsedAlbumTitle === '') {
            return null;
        }

        $songId = (int) ($songPayload['id'] ?? 0);
        $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songPayload['title'] ?? ''));

        if ($songId <= 0 && $songTitle === '') {
            return null;
        }

        $currentAlbumTitle = is_array($currentAlbumPayload)
            ? $this->extractAlbumPayloadTitle($currentAlbumPayload)
            : $this->extractSongAlbumTitle($songPayload);

        $albumCandidates = collect($this->artistAlbumSummariesForPreferredPayload($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload) && (int) ($albumPayload['id'] ?? 0) > 0)
            ->map(function (array $albumPayload) use ($parsedAlbumTitle, $parsedTrack, $songPayload): ?array {
                $candidateTitle = $this->extractAlbumPayloadTitle($albumPayload) ?? '';

                if ($candidateTitle === '') {
                    return null;
                }

                $identityMatch = $this->albumTitlesShareIdentity($parsedAlbumTitle, $candidateTitle);
                $similarity = $this->albumTitleSimilarity($parsedAlbumTitle, $candidateTitle);

                if (! $identityMatch && $similarity < 0.78) {
                    return null;
                }

                return [
                    'album' => $albumPayload,
                    'similarity' => $similarity,
                    'identity' => $identityMatch,
                    'non_original' => $this->hasNonOriginalAlbumMarkers($candidateTitle),
                    'demo' => $this->hasDemoMarkers($candidateTitle),
                    'unreleased' => $this->hasUnreleasedMarkers($candidateTitle),
                    'compilation' => $this->hasCompilationMarkers($candidateTitle),
                    'single_like' => $this->hasSingleLikeAlbumMarkers($candidateTitle, (string) ($songPayload['title'] ?? $parsedTrack->title)),
                    'release_sort' => $this->releaseSortValue($albumPayload),
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                if (($left['identity'] ?? false) !== ($right['identity'] ?? false)) {
                    return ((bool) ($right['identity'] ?? false)) <=> ((bool) ($left['identity'] ?? false));
                }

                return $this->comparePreferredAlbumPayloadCandidates($left, $right);
            })
            ->values()
            ->all();

        if ($albumCandidates === []) {
            return null;
        }

        if ($currentAlbumTitle !== null && $currentAlbumTitle !== '') {
            foreach ($albumCandidates as $candidate) {
                $candidateAlbum = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];
                $candidateTitle = $this->extractAlbumPayloadTitle($candidateAlbum) ?? '';

                if ($candidateTitle !== '' && $this->albumTitlesShareIdentity($currentAlbumTitle, $candidateTitle)) {
                    return $candidateAlbum;
                }
            }
        }

        foreach (array_slice($albumCandidates, 0, 6) as $candidate) {
            $albumPayload = is_array($candidate['album'] ?? null) ? $candidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            foreach ($this->albumTrackSongs($albumId, $albumPayload) as $songSummary) {
                if (! is_array($songSummary)) {
                    continue;
                }

                $candidateSongId = (int) ($songSummary['id'] ?? 0);
                $candidateSongTitle = (string) ($songSummary['title'] ?? '');

                if ($songId > 0 && $candidateSongId === $songId) {
                    return $albumPayload;
                }

                if ($songTitle === '' || $candidateSongTitle === '') {
                    continue;
                }

                if (GeniusNameMatcher::score($songTitle, GeniusNameMatcher::canonicalTrack($candidateSongTitle)) < 0.94) {
                    continue;
                }

                if ($this->versionMatchAdjustment($this->parsedTrackVersionContext($parsedTrack), $candidateSongTitle) < -0.18) {
                    continue;
                }

                return $albumPayload;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $currentAlbumPayload
     */
    private function shouldResolvePreferredAlbumPayload(
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?array $currentAlbumPayload = null,
    ): bool {
        if (! $this->parsedTrackPrefersOriginalAlbum($parsedTrack)) {
            return false;
        }

        $currentAlbumTitle = is_array($currentAlbumPayload)
            ? $this->extractAlbumPayloadTitle($currentAlbumPayload)
            : $this->extractSongAlbumTitle($songPayload);

        if ($currentAlbumTitle === null || $currentAlbumTitle === '') {
            return true;
        }

        if ($this->hasNonOriginalAlbumMarkers($currentAlbumTitle) || $this->hasUnreleasedMarkers($currentAlbumTitle)) {
            return true;
        }

        if ($this->hasSingleLikeAlbumMarkers($currentAlbumTitle, (string) ($songPayload['title'] ?? $parsedTrack->title))) {
            return true;
        }

        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return false;
        }

        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);

        if ($parsedAlbumTitle === '') {
            return false;
        }

        if (! $this->albumTitlesShareIdentity($parsedAlbumTitle, (string) $currentAlbumTitle)) {
            return true;
        }

        return $this->hasDemoMarkers($currentAlbumTitle)
            || $this->hasCompilationMarkers($currentAlbumTitle)
            || $this->hasUnreleasedMarkers($currentAlbumTitle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function artistAlbumSummariesForPreferredPayload(int $artistGeniusId): array
    {
        if ($artistGeniusId === $this->currentArtistGeniusId && $this->currentArtistAlbumSummaries !== []) {
            return $this->currentArtistAlbumSummaries;
        }

        return collect($this->geniusClient->allArtistAlbums($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload))
            ->values()
            ->all();
    }

    private function preferredAlbumArtistGeniusId(Artist $primaryArtist, array $songPayload): int
    {
        $artistIds = collect((array) ($songPayload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && (int) ($artistPayload['id'] ?? 0) > 0)
            ->map(fn (array $artistPayload) => (int) ($artistPayload['id'] ?? 0))
            ->values()
            ->all();

        if ($artistIds !== []) {
            return (int) $artistIds[0];
        }

        if ((int) ($primaryArtist->genius_id ?? 0) > 0) {
            return (int) $primaryArtist->genius_id;
        }

        return (int) ($this->currentSyncArtist?->genius_id ?? 0);
    }

    private function primePreferredAlbumPayloadCatalog(int $artistGeniusId): void
    {
        if (array_key_exists($artistGeniusId, $this->preferredAlbumBySongId)) {
            return;
        }

        $this->preferredAlbumBySongId[$artistGeniusId] = [];
        $this->preferredAlbumCandidatesByTitle[$artistGeniusId] = [];

        $albumCandidates = collect($this->artistAlbumSummariesForPreferredPayload($artistGeniusId))
            ->filter(fn ($albumPayload) => is_array($albumPayload) && (int) ($albumPayload['id'] ?? 0) > 0)
            ->map(function (array $albumPayload): array {
                $albumTitle = $this->extractAlbumPayloadTitle($albumPayload) ?? '';

                return [
                    'album' => $albumPayload,
                    'title' => $albumTitle,
                    'single_like' => $this->hasSingleLikeAlbumMarkers($albumTitle),
                    'non_original' => $this->hasNonOriginalAlbumMarkers($albumTitle),
                    'demo' => $this->hasDemoMarkers($albumTitle),
                    'unreleased' => $this->hasUnreleasedMarkers($albumTitle),
                    'compilation' => $this->hasCompilationMarkers($albumTitle),
                    'release_sort' => $this->releaseSortValue($albumPayload),
                ];
            })
            ->sort(fn (array $left, array $right): int => $this->comparePreferredAlbumPayloadCandidates($left, $right))
            ->values()
            ->all();

        foreach ($albumCandidates as $albumCandidate) {
            $albumPayload = is_array($albumCandidate['album'] ?? null) ? $albumCandidate['album'] : [];
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            foreach ($this->albumTrackSongs($albumId, $albumPayload) as $songSummary) {
                if (! is_array($songSummary)) {
                    continue;
                }

                $songId = (int) ($songSummary['id'] ?? 0);
                $songTitle = GeniusNameMatcher::canonicalTrack((string) ($songSummary['title'] ?? ''));

                if ($songId > 0 && ! isset($this->preferredAlbumBySongId[$artistGeniusId][$songId])) {
                    $this->preferredAlbumBySongId[$artistGeniusId][$songId] = $albumPayload;
                }

                if ($songTitle === '') {
                    continue;
                }

                $this->preferredAlbumCandidatesByTitle[$artistGeniusId][$songTitle][] = [
                    'album' => $albumPayload,
                    'song' => $songSummary,
                ];
            }
        }
    }

    private function comparePreferredAlbumPayloadCandidates(array $left, array $right): int
    {
        if (($left['single_like'] ?? false) !== ($right['single_like'] ?? false)) {
            return ((bool) ($left['single_like'] ?? false)) <=> ((bool) ($right['single_like'] ?? false));
        }

        if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
            return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
        }

        if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
            return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
        }

        if (($left['unreleased'] ?? false) !== ($right['unreleased'] ?? false)) {
            return ((bool) ($left['unreleased'] ?? false)) <=> ((bool) ($right['unreleased'] ?? false));
        }

        if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
            return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
        }

        $leftReleaseSort = (int) ($left['release_sort'] ?? PHP_INT_MAX);
        $rightReleaseSort = (int) ($right['release_sort'] ?? PHP_INT_MAX);

        if ($leftReleaseSort !== $rightReleaseSort) {
            return $leftReleaseSort <=> $rightReleaseSort;
        }

        return ((int) data_get($left, 'album.id', PHP_INT_MAX)) <=> ((int) data_get($right, 'album.id', PHP_INT_MAX));
    }

    /**
     * @param  array<int, array<string, mixed>>  $albumCandidates
     */
    private function shouldSkipAlbumTrackPreloadForParsedTrack(ParsedTrack $parsedTrack, array $albumCandidates): bool
    {
        if (! $this->parsedTrackUsesTrackNumberSignal($parsedTrack)) {
            return false;
        }

        $bestCandidate = $albumCandidates[0] ?? null;

        if (! is_array($bestCandidate) || (bool) ($bestCandidate['non_original_album'] ?? false)) {
            return false;
        }

        if ($parsedTrack->releaseYear !== null && (float) ($bestCandidate['release_year_score'] ?? 0.0) < 0.0) {
            return false;
        }

        return (float) ($bestCandidate['album_similarity'] ?? 0.0) >= 0.88
            || (float) ($bestCandidate['album_score'] ?? 0.0) >= 0.12;
    }

    private function shouldUseAlbumPageContextAlbum(Album $album, ParsedTrack $parsedTrack, ?string $payloadAlbumTitle): bool
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE) {
            return false;
        }

        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;
        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));

        if ($currentTitle === '') {
            return false;
        }

        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return $parsedAlbumTitle !== null
                && $this->albumTitlesShareIdentity($currentTitle, $parsedAlbumTitle);
        }

        return $this->albumTitlesShareIdentity($currentTitle, $payloadAlbumTitle);
    }

    /**
     * @param  array<string, mixed>  $songPayload
     */
    private function shouldAllowAlbumPageContextAlbumOverride(
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?Album $contextAlbum = null,
        ?string $payloadAlbumTitle = null,
    ): bool {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            || in_array('ringtone', $this->parsedTrackVersionFlags($parsedTrack), true)) {
            return false;
        }

        $currentArtist = $this->currentSyncArtist;

        if (! $currentArtist instanceof Artist) {
            return false;
        }

        $contextAlbum ??= $this->resolveAlbumPageContextAlbum($currentArtist, $parsedTrack);
        $contextAlbum ??= $this->findLocalAlbumByComparableTitle($currentArtist, (string) $parsedTrack->albumTitle);

        if (! $contextAlbum instanceof Album) {
            return false;
        }

        $parsedTitle = GeniusNameMatcher::canonicalTrack((string) $parsedTrack->title);

        if ($parsedTitle === '' || ! $this->songTitleMatchesParsedTrack($parsedTrack, $songPayload)) {
            return false;
        }

        $payloadAlbumTitle = $this->shouldPersistAlbumTitle($payloadAlbumTitle)
            ? $this->storedAlbumTitle((string) $payloadAlbumTitle)
            : null;
        $parsedAlbumTitle = $this->storedAlbumTitle((string) $parsedTrack->albumTitle);
        $contextAlbumTitle = $this->storedAlbumTitle((string) ($contextAlbum->title ?? ''));

        if ($parsedAlbumTitle === ''
            || $contextAlbumTitle === ''
            || ! $this->albumTitlesShareIdentity($contextAlbumTitle, $parsedAlbumTitle)) {
            return false;
        }

        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return true;
        }

        if (! $this->isTrustedAlbumPageContext($currentArtist, $parsedTrack)
            && ! $this->hasSingleLikeAlbumMarkers($parsedAlbumTitle, (string) $parsedTrack->title)
            && ! $this->hasSingleLikeAlbumMarkers($contextAlbumTitle, (string) $parsedTrack->title)) {
            return false;
        }

        $normalizedPayloadAlbumTitle = GeniusNameMatcher::canonicalTrack($payloadAlbumTitle);

        if ($normalizedPayloadAlbumTitle !== '' && $normalizedPayloadAlbumTitle === $parsedTitle) {
            return false;
        }

        return ! $this->albumTitlesShareIdentity($contextAlbumTitle, $payloadAlbumTitle);
    }

    /**
     * @param  array<string, mixed>  $songPayload
     */
    private function shouldAllowAlbumPageContextTrackNumberMatch(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        if (! $this->parsedTrackUsesTrackNumberSignal($parsedTrack)) {
            return false;
        }

        $currentArtist = $this->currentSyncArtist;

        if (! $currentArtist instanceof Artist) {
            return false;
        }

        $contextAlbum = $this->resolveAlbumPageContextAlbum($currentArtist, $parsedTrack);

        if (! $contextAlbum instanceof Album) {
            return false;
        }

        $songTrackNumber = $this->extractSongTrackNumber($songPayload);

        return $songTrackNumber !== null
            && (int) $parsedTrack->trackNumber === $songTrackNumber;
    }

    /**
     * @return string[]
     */
    private function orderedAlbumCandidateTitles(ParsedTrack $parsedTrack, ?string $payloadAlbumTitle): array
    {
        $parsedAlbumTitle = $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            ? $this->storedAlbumTitle((string) $parsedTrack->albumTitle)
            : null;
        $payloadAlbumTitle = $this->shouldPersistAlbumTitle($payloadAlbumTitle)
            ? $this->storedAlbumTitle((string) $payloadAlbumTitle)
            : null;

        if ($parsedAlbumTitle === null && $payloadAlbumTitle === null) {
            return [];
        }

        if ($parsedAlbumTitle === null) {
            return [$payloadAlbumTitle];
        }

        if ($payloadAlbumTitle === null) {
            return [$parsedAlbumTitle];
        }

        $similarity = $this->albumTitleSimilarity($parsedAlbumTitle, $payloadAlbumTitle);

        if ($this->hasSingleLikeAlbumMarkers($payloadAlbumTitle, $parsedTrack->title)
            && ! $this->hasSingleLikeAlbumMarkers($parsedAlbumTitle, $parsedTrack->title)
            && ! $this->albumTitlesShareIdentity($parsedAlbumTitle, $payloadAlbumTitle)) {
            return [$parsedAlbumTitle, $payloadAlbumTitle];
        }

        if ($similarity < 0.78) {
            return array_values(array_unique([$payloadAlbumTitle, $parsedAlbumTitle]));
        }

        if ($this->parsedTrackPrefersOriginalAlbum($parsedTrack)
            && ! $this->hasNonOriginalAlbumMarkers($parsedAlbumTitle)
            && $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)) {
            return array_values(array_unique([$parsedAlbumTitle, $payloadAlbumTitle]));
        }

        return array_values(array_unique([$payloadAlbumTitle, $parsedAlbumTitle]));
    }

    private function shouldUseLocalAlbumCandidate(
        Album $album,
        ParsedTrack $parsedTrack,
        string $candidateTitle,
        ?string $payloadAlbumTitle,
    ): bool {
        if ($payloadAlbumTitle === null || $payloadAlbumTitle === '') {
            return true;
        }

        $candidateTitle = $this->storedAlbumTitle($candidateTitle);
        $payloadAlbumTitle = $this->storedAlbumTitle($payloadAlbumTitle);

        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));
        $prefersOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);

        if ($candidateTitle === '') {
            return true;
        }

        if ($prefersOriginalAlbum
            && $currentTitle !== ''
            && $this->hasNonOriginalAlbumMarkers($currentTitle)
            && (
                ! $this->hasNonOriginalAlbumMarkers($candidateTitle)
                || ! $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)
            )) {
            return false;
        }

        if ($currentTitle !== '' && $this->albumTitlesShareIdentity($currentTitle, $payloadAlbumTitle)) {
            return true;
        }

        if ($currentTitle !== ''
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && $this->albumTitlesShareIdentity($currentTitle, (string) $parsedTrack->albumTitle)
            && $this->isWeakAlbumTitleSignal($payloadAlbumTitle)) {
            return true;
        }

        if ($prefersOriginalAlbum
            && ! $this->hasNonOriginalAlbumMarkers($candidateTitle)
            && $this->hasNonOriginalAlbumMarkers($payloadAlbumTitle)) {
            return true;
        }

        $normalizedCandidateTrackTitle = GeniusNameMatcher::canonicalTrack($currentTitle !== '' ? $currentTitle : $candidateTitle);
        $normalizedParsedTrackTitle = GeniusNameMatcher::canonicalTrack((string) $parsedTrack->title);

        return $normalizedCandidateTrackTitle !== ''
            && $normalizedCandidateTrackTitle === $normalizedParsedTrackTitle;
    }

    private function shouldRefreshExistingAlbumFromPayload(Album $album, ParsedTrack $parsedTrack, ?array $payload): bool
    {
        if (! is_array($payload) || ! $this->shouldCreateAlbumFromPayload($payload)) {
            return false;
        }

        $payloadTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        if ($payloadTitle === '') {
            return false;
        }

        if ((int) ($album->genius_id ?? 0) > 0 && (int) ($album->genius_id ?? 0) === (int) ($payload['id'] ?? 0)) {
            return true;
        }

        $currentTitle = $this->storedAlbumTitle((string) ($album->title ?? ''));
        $prefersOriginalAlbum = $this->parsedTrackPrefersOriginalAlbum($parsedTrack);
        $payloadIsNonOriginal = $this->hasNonOriginalAlbumMarkers($payloadTitle);
        $currentIsNonOriginal = $this->hasNonOriginalAlbumMarkers($currentTitle);

        if ($currentTitle === '') {
            return true;
        }

        if (! $this->albumTitlesShareIdentity($currentTitle, $payloadTitle)) {
            return false;
        }

        if ($prefersOriginalAlbum && $currentIsNonOriginal && ! $payloadIsNonOriginal) {
            return false;
        }

        return ! (
            $prefersOriginalAlbum
            && ! $this->hasNonOriginalAlbumMarkers($currentTitle)
            && $payloadIsNonOriginal
            && $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            && $this->albumTitleSimilarity((string) $parsedTrack->albumTitle, $currentTitle) >= 0.82
        );
    }

    private function resolveAlbumPageContextAlbum(Artist $artist, ParsedTrack $parsedTrack): ?Album
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return null;
        }

        $albumId = $this->albumPageAlbumContext[$this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle)] ?? null;

        if (! is_int($albumId) || $albumId <= 0) {
            return null;
        }

        return Album::query()->find($albumId);
    }

    private function isTrustedAlbumPageContext(Artist $artist, ParsedTrack $parsedTrack): bool
    {
        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return false;
        }

        return (bool) ($this->albumPageAlbumContextTrusted[$this->albumPageContextKey($artist, (string) $parsedTrack->albumTitle)] ?? false);
    }

    private function albumPageContextKey(Artist $artist, string $albumTitle): string
    {
        return $artist->id . ':' . md5($this->albumComparableLookupKey($albumTitle) . '|' . $this->storedAlbumTitle($albumTitle));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Album>
     */
    private function localAlbumsForArtist(Artist $artist): EloquentCollection
    {
        if (! array_key_exists($artist->id, $this->localArtistAlbums)) {
            $this->localArtistAlbums[$artist->id] = Album::query()
                ->where('artist_id', $artist->id)
                ->get();
        }

        return $this->localArtistAlbums[$artist->id];
    }

    private function rememberLocalAlbum(Album $album, ?int $previousArtistId = null): void
    {
        if ($previousArtistId !== null && $previousArtistId !== (int) $album->artist_id) {
            unset($this->localArtistAlbums[$previousArtistId]);
        }

        unset($this->localArtistAlbums[(int) $album->artist_id]);
    }

    private function findLocalAlbumByComparableTitle(Artist $artist, string $albumTitle): ?Album
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedStoredAlbumTitle = Str::lower($storedAlbumTitle);
        $normalizedLookupKey = $this->albumComparableLookupKey($storedAlbumTitle);
        $prefersOriginalAlbum = $this->versionFlags($storedAlbumTitle) === [];
        $candidates = [];

        foreach ($this->localAlbumsForArtist($artist) as $album) {
            $candidateTitle = $this->storedAlbumTitle((string) $album->title);

            if ($candidateTitle === '') {
                continue;
            }

            $exactMatch = Str::lower($candidateTitle) === $normalizedStoredAlbumTitle;
            $lookupMatch = $normalizedLookupKey !== '' && $normalizedLookupKey === $this->albumComparableLookupKey($candidateTitle);

            if (! $exactMatch && ! $lookupMatch) {
                continue;
            }

            $candidates[] = [
                'album' => $album,
                'exact' => $exactMatch,
                'non_original' => $this->hasNonOriginalAlbumMarkers($candidateTitle),
                'demo' => $this->hasDemoMarkers($candidateTitle),
                'compilation' => $this->hasCompilationMarkers($candidateTitle),
                'prefer_original' => $prefersOriginalAlbum,
                'release_date' => $album->release_date?->format('Y-m-d'),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if (($left['exact'] ?? false) !== ($right['exact'] ?? false)) {
                return ($left['exact'] ?? false) ? -1 : 1;
            }

            if ((bool) ($left['prefer_original'] ?? false)) {
                if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
                    return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
                }

                if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                    return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
                }

                if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                    return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
                }
            }

            $leftReleaseDate = (string) ($left['release_date'] ?? '9999-12-31');
            $rightReleaseDate = (string) ($right['release_date'] ?? '9999-12-31');
            $releaseDateCompare = $leftReleaseDate <=> $rightReleaseDate;

            if ($releaseDateCompare !== 0) {
                return $releaseDateCompare;
            }

            return ((int) ($left['album']->id ?? 0)) <=> ((int) ($right['album']->id ?? 0));
        });

        $bestCandidate = $candidates[0] ?? null;

        return $bestCandidate['album'] ?? null;
    }

    private function refreshExistingAlbumFromGenius(Album $album, Artist $fallbackArtist, array $payload, ?string $muzofondCoverImageUrl = null): void
    {
        $previousArtistId = $album->artist_id;
        $albumArtistPayloads = collect((array) ($payload['primary_artists'] ?? []))
            ->filter(fn ($artistPayload) => is_array($artistPayload) && isset($artistPayload['id']))
            ->values();

        if ($albumArtistPayloads->isEmpty() && is_array($payload['artist'] ?? null) && isset($payload['artist']['id'])) {
            $albumArtistPayloads = collect([$payload['artist']]);
        }

        $albumArtists = new EloquentCollection();

        foreach ($albumArtistPayloads as $index => $artistPayload) {
            [$albumArtist] = $this->upsertArtistFromGenius($index === 0 ? $fallbackArtist : null, $artistPayload);

            if ($this->isVariousArtistsName($albumArtist->name)) {
                continue;
            }

            $albumArtists->push($albumArtist);
        }

        if ($albumArtists->isEmpty()) {
            if (method_exists($album, 'artists')) {
                $album->loadMissing('artists');

                if ($album->artists->isNotEmpty()) {
                    $albumArtists = new EloquentCollection($album->artists->all());
                }
            }

            if ($albumArtists->isEmpty()) {
                $albumArtists->push($fallbackArtist);
            }
        }

        $albumArtists = $this->rejectVariousArtists(new EloquentCollection(
            $albumArtists
                ->unique(fn (Artist $artist) => $artist->id)
                ->values()
                ->all()
        ));
        /** @var Artist $primaryAlbumArtist */
        $primaryAlbumArtist = $this->resolvePreferredAlbumArtist($fallbackArtist, $albumArtists);

        $incomingGeniusId = (int) ($payload['id'] ?? 0);
        $safeGeniusId = $album->genius_id;

        if ($incomingGeniusId > 0) {
            $conflictingAlbum = Album::query()
                ->where('genius_id', $incomingGeniusId)
                ->whereKeyNot($album->id)
                ->first();

            if (! $conflictingAlbum instanceof Album) {
                $safeGeniusId = $incomingGeniusId;
            }
        }

        $incomingTitle = $this->storedAlbumTitle((string) ($payload['name'] ?? $payload['title'] ?? ''));

        $albumPayload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryAlbumArtist->id,
            'title' => $this->shouldReplaceAlbumTitleFromGenius($album, $incomingTitle)
                ? $incomingTitle
                : $album->title,
            'release_date' => $this->completeAlbumPayloadReleaseDate($payload)
                ?? $album->release_date?->toDateString(),
            'release_date_components' => is_array($payload['release_date_components'] ?? null)
                ? $payload['release_date_components']
                : $album->release_date_components,
            'cover_image_url' => ArtworkUrl::firstUsable(
                $payload['cover_art_thumbnail_url'] ?? null,
                $payload['cover_art_url'] ?? null,
                $payload['image_url'] ?? null,
                $muzofondCoverImageUrl,
                $album->cover_image_url,
            ),
            'genius_id' => $safeGeniusId,
        ]);

        try {
            $album->forceFill($albumPayload)->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception, 'albums_genius_id_unique') || (int) ($albumPayload['genius_id'] ?? 0) <= 0) {
                throw $exception;
            }

            $existingAlbum = Album::query()
                ->where('genius_id', (int) $albumPayload['genius_id'])
                ->first();

            if (! $existingAlbum instanceof Album) {
                throw $exception;
            }

            $album = $existingAlbum;
            $album->forceFill($albumPayload)->save();
        }

        $this->rememberLocalAlbum($album, $previousArtistId);

        if (method_exists($album, 'artists') && $albumArtists->isNotEmpty()) {
            $album->artists()->sync($albumArtists->pluck('id')->unique()->values()->all());
        }
    }

    private function shouldReplaceAlbumTitleFromGenius(Album $album, string $incomingTitle): bool
    {
        if ($incomingTitle === '') {
            return false;
        }

        $currentRawTitle = (string) ($album->title ?? '');
        $currentTitle = $this->storedAlbumTitle($currentRawTitle);

        if ($currentTitle === '') {
            return true;
        }

        if ($currentTitle === $incomingTitle && $currentRawTitle !== $incomingTitle) {
            return true;
        }

        if ($currentTitle === $incomingTitle) {
            return false;
        }

        if ($this->albumTitleReadabilityScore($incomingTitle) > ($this->albumTitleReadabilityScore($currentTitle) + 0.12)) {
            return true;
        }

        return false;
    }

    private function albumTitlesShareIdentity(string $left, string $right): bool
    {
        $left = $this->storedAlbumTitle($left);
        $right = $this->storedAlbumTitle($right);

        if ($left === '' || $right === '') {
            return false;
        }

        if (Str::lower($left) === Str::lower($right)) {
            return true;
        }

        if ($this->albumComparableLookupKey($left) !== '' && $this->albumComparableLookupKey($left) === $this->albumComparableLookupKey($right)) {
            return true;
        }

        return $this->albumDigitSignatureScore($left, $right) >= 0.84;
    }

    private function resolvePreferredAlbumArtist(Artist $fallbackArtist, EloquentCollection $albumArtists): Artist
    {
        /** @var Artist|null $firstAlbumArtist */
        $firstAlbumArtist = $albumArtists->first();

        if ($firstAlbumArtist && ! $this->isVariousArtistsName($firstAlbumArtist->name)) {
            return $firstAlbumArtist;
        }

        return $fallbackArtist;
    }

    private function resolveTrackPrimaryArtist(
        Artist $fallbackArtist,
        EloquentCollection $primaryPerformers,
        EloquentCollection $performers,
    ): Artist {
        // For a confirmed song match, Genius credits take precedence over the Muzofond page context.
        /** @var Artist|null $firstPrimary */
        $firstPrimary = $primaryPerformers->first(
            fn (mixed $artist): bool => $artist instanceof Artist && ! $this->isVariousArtistsName($artist->name)
        );

        if ($firstPrimary instanceof Artist) {
            return $firstPrimary;
        }

        /** @var Artist|null $firstPerformer */
        $firstPerformer = $performers->first(
            fn (mixed $artist): bool => $artist instanceof Artist && ! $this->isVariousArtistsName($artist->name)
        );

        if ($firstPerformer instanceof Artist) {
            return $firstPerformer;
        }

        return $fallbackArtist;
    }

    private function resolveTrackNumber(ParsedTrack $parsedTrack, array $songPayload, ?Album $album): ?int
    {
        if ($album instanceof Album) {
            $albumPagePosition = $this->incomingTrackPositionForAlbum($parsedTrack, $album, null);

            if ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE && $albumPagePosition !== null) {
                return $albumPagePosition;
            }
        }

        if ($album instanceof Album) {
            $albumTrackNumber = $this->geniusAlbumTrackNumber($album, $songPayload);

            if ($albumTrackNumber !== null) {
                return $albumTrackNumber;
            }
        }

        $songTrackNumber = $this->extractSongTrackNumber($songPayload);

        if ($songTrackNumber !== null) {
            return $songTrackNumber;
        }

        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            ? $parsedTrack->trackNumber
            : null;
    }

    private function artistMatchesFallback(Artist $candidate, Artist $fallbackArtist): bool
    {
        if ($candidate->id === $fallbackArtist->id) {
            return true;
        }

        if ($candidate->genius_id && $fallbackArtist->genius_id && (int) $candidate->genius_id === (int) $fallbackArtist->genius_id) {
            return true;
        }

        return GeniusNameMatcher::bestArtistScore($fallbackArtist->name, [$candidate->name]) >= 0.82;
    }

    private function isVariousArtistsName(?string $name): bool
    {
        return in_array(GeniusNameMatcher::normalizeLoose((string) ($name ?? '')), [
            'various artists',
            'various',
            'raznye ispolniteli',
            'sbornik',
        ], true);
    }

    private function resolveReleaseYear(array $songPayload, ParsedTrack $parsedTrack): ?int
    {
        if (! empty($songPayload['release_date_components']['year'])) {
            return (int) $songPayload['release_date_components']['year'];
        }

        if (! empty($songPayload['release_date']) && preg_match('/\b(19|20)\d{2}\b/', (string) $songPayload['release_date'], $matches) === 1) {
            return (int) $matches[0];
        }

        return $parsedTrack->releaseYear;
    }

    private function releaseSortValue(array $payload): int
    {
        $year = (int) data_get($payload, 'release_date_components.year', 0);
        $month = (int) data_get($payload, 'release_date_components.month', 0);
        $day = (int) data_get($payload, 'release_date_components.day', 0);

        if ($year > 0) {
            return ($year * 10000) + (max(1, $month) * 100) + max(1, $day);
        }

        $releaseDate = (string) ($payload['release_date'] ?? data_get($payload, 'album.release_date', ''));

        if ($releaseDate !== '' && preg_match('/\b(?<year>(?:19|20)\d{2})(?:-(?<month>\d{1,2}))?(?:-(?<day>\d{1,2}))?\b/', $releaseDate, $matches) === 1) {
            return ((int) ($matches['year'] ?? 0) * 10000)
                + (max(1, (int) ($matches['month'] ?? 1)) * 100)
                + max(1, (int) ($matches['day'] ?? 1));
        }

        return PHP_INT_MAX;
    }

    /**
     * @return Artist[]
     */
    private function resolveFallbackCreditedArtists(ParsedArtistPage $page, ParsedTrack $parsedTrack, Artist $primaryArtist): array
    {
        $artists = [$primaryArtist];
        $pageArtistReferenceNames = $this->currentPageArtistReferenceNames !== []
            ? $this->currentPageArtistReferenceNames
            : $this->pageArtistReferenceNames($page);

        foreach ($parsedTrack->artistNames as $artistName) {
            foreach (GeniusNameMatcher::splitArtistCredits($artistName, $page->artistName) as $creditedArtistName) {
                $normalizedName = GeniusNameMatcher::normalizeLoose($creditedArtistName);

                if ($normalizedName === '' || $this->bestReferenceArtistScore($pageArtistReferenceNames, [$creditedArtistName]) >= 0.92) {
                    continue;
                }

                $artists[] = $this->upsertArtistByName($creditedArtistName);
            }
        }

        return collect($artists)
            ->unique(fn (Artist $artist) => $artist->id)
            ->values()
            ->all();
    }

    private function upsertArtistByName(string $name): Artist
    {
        $storedName = GeniusNameMatcher::storageValue($name);

        $artist = Artist::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($storedName)])
            ->first();

        if ($artist) {
            return $artist;
        }

        $baseSlug = Str::slug($storedName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'artist';

        return Artist::create(GeniusNameMatcher::sanitizeForDatabase([
            'name' => $storedName,
            'slug' => $this->uniqueArtistSlug($baseSlug),
            'image_url' => null,
            'description_preview' => null,
            'genius_id' => null,
        ]));
    }

    private function resolveFallbackAlbumId(Artist $primaryArtist, ParsedTrack $parsedTrack): ?int
    {
        if (! $this->shouldAttachFallbackTrackToAlbum($parsedTrack)) {
            return null;
        }

        $albumTitle = $this->storedAlbumTitle((string) ($parsedTrack->albumTitle ?? ''));

        if ($albumTitle === '') {
            return null;
        }

        $album = $this->findBestLocalFallbackAlbumMatch($primaryArtist, $albumTitle);

        if ($album instanceof Album
            && $this->albumTracklistAllows($album, $parsedTrack)
            && $this->albumMembershipHasUniqueVersion($album, $parsedTrack, $this->findTrackByAudioIdentity($parsedTrack))) {
            $this->refreshExistingAlbumTitleFromParsedTrack($album, $albumTitle);
            $this->fillAlbumCoverFromMuzofond($album, $parsedTrack);

            return $album->id;
        }

        return null;
    }

    private function fillAlbumCoverFromMuzofond(Album $album, ParsedTrack $parsedTrack): Album
    {
        $muzofondCover = ArtworkUrl::usable($parsedTrack->albumCoverImageUrl);

        if (ArtworkUrl::usable($album->cover_image_url) === null && $muzofondCover !== null) {
            $album->forceFill(['cover_image_url' => $muzofondCover])->save();
        }

        return $album;
    }

    private function sourceAlbumTracklistAllows(ParsedTrack $parsedTrack, int $songId): bool
    {
        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)
            || ! $this->currentSyncArtist instanceof Artist) {
            return true;
        }

        $album = $this->findBestLocalFallbackAlbumMatch($this->currentSyncArtist, (string) $parsedTrack->albumTitle);

        return ! $album instanceof Album || $this->albumTracklistAllows($album, $parsedTrack, $songId);
    }

    private function albumTracklistAllows(Album $album, ParsedTrack $parsedTrack, ?int $songId = null): bool
    {
        $albumId = (int) ($album->genius_id ?? 0);

        if ($albumId <= 0) {
            return true;
        }

        // The artist catalog already preloads available album tracklists. Do not
        // start an extra network request for every source fallback candidate.
        if (! array_key_exists($albumId, $this->albumTrackSongCache)) {
            return true;
        }

        $songs = $this->albumTrackSongs($albumId, [
            'id' => $albumId,
            'name' => (string) $album->title,
        ]);

        if ($songs === []) {
            return true;
        }

        foreach ($songs as $index => $song) {
            if ($songId !== null && $songId > 0 && (int) ($song['id'] ?? 0) !== $songId) {
                continue;
            }

            if ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
                && $parsedTrack->trackNumber !== null
                && $parsedTrack->trackNumber > 0
                && $parsedTrack->trackNumber !== (int) ($song['track_number'] ?? ($index + 1))) {
                continue;
            }

            if (GeniusNameMatcher::score(
                GeniusNameMatcher::canonicalTrack($parsedTrack->title),
                GeniusNameMatcher::canonicalTrack((string) ($song['title'] ?? '')),
            ) >= 0.94 && $this->versionMatchAdjustment(
                $this->parsedTrackVersionContext($parsedTrack),
                (string) ($song['title'] ?? ''),
            ) >= -0.18) {
                return true;
            }
        }

        return false;
    }

    private function refreshExistingAlbumTitleFromParsedTrack(Album $album, string $albumTitle): void
    {
        $candidateTitle = $this->storedAlbumTitle($albumTitle);
        $currentTitle = (string) ($album->title ?? '');

        if ($candidateTitle === '' || $candidateTitle === $currentTitle) {
            return;
        }

        if ($this->albumTitleReadabilityScore($candidateTitle) <= ($this->albumTitleReadabilityScore($currentTitle) + 0.12)) {
            return;
        }

        $album->forceFill([
            'title' => $candidateTitle,
        ])->save();
    }

    private function albumTitleReadabilityScore(string $title): float
    {
        $title = trim($title);

        if ($title === '') {
            return 0.0;
        }

        $letters = preg_match_all('/\p{L}/u', $title);
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $title);
        $latin = preg_match_all('/\p{Latin}/u', $title);
        $digits = preg_match_all('/\d/u', $title);
        $broken = preg_match_all('/(?:Р[А-Яа-яЁёA-Za-z]|С[А-Яа-яЁёA-Za-z]|Ã.|Â.|â.|Ð.|Ñ.|вЂ|в€)/u', $title);
        $meaningful = max(1, (int) $letters + (int) $digits);
        $score = (((int) $cyrillic + (int) $latin + (int) $digits) / $meaningful)
            + ((int) $cyrillic > 0 ? 0.35 : 0.0)
            + ((int) $latin > 0 ? 0.08 : 0.0)
            - ((int) $broken * 0.08);

        if ($this->storedAlbumTitle($title) === $title) {
            $score += 0.08;
        }

        return round($score, 4);
    }

    private function shouldAttachFallbackTrackToAlbum(ParsedTrack $parsedTrack): bool
    {
        if (in_array('ringtone', $this->parsedTrackVersionFlags($parsedTrack), true)
            || ! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return false;
        }

        return $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
            || ($parsedTrack->sourceType === ParsedTrack::SOURCE_ARTIST_PAGE
                && GeniusNameMatcher::canonicalTrack((string) $parsedTrack->albumTitle)
                    !== GeniusNameMatcher::canonicalTrack($parsedTrack->title));
    }

    private function findBestLocalFallbackAlbumMatch(Artist $primaryArtist, string $albumTitle): ?Album
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedStoredAlbumTitle = Str::lower($storedAlbumTitle);
        $comparisonTitle = $this->albumComparableLookupKey($storedAlbumTitle);
        $prefersOriginalAlbum = $this->versionFlags($storedAlbumTitle) === [];
        $candidates = [];

        foreach ($this->localAlbumsForArtist($primaryArtist) as $album) {
            $candidateTitle = $this->storedAlbumTitle((string) $album->title);

            if ($candidateTitle === '') {
                continue;
            }

            $candidateLookupKey = $this->albumComparableLookupKey($candidateTitle);
            $exactMatch = Str::lower($candidateTitle) === $normalizedStoredAlbumTitle
                || ($comparisonTitle !== '' && $comparisonTitle === $candidateLookupKey);
            $identityMatch = $exactMatch || $this->albumTitlesShareIdentity($storedAlbumTitle, $candidateTitle);
            $baseScore = $this->albumTitleSimilarity($storedAlbumTitle, $candidateTitle);

            if (! $identityMatch) {
                continue;
            }

            $versionAdjustment = $this->versionMatchAdjustment($storedAlbumTitle, $candidateTitle);

            if ($baseScore >= 0.9 && $versionAdjustment < -0.04) {
                $versionAdjustment = -0.04;
            }

            $score = $exactMatch ? 1.4 : ($baseScore + $versionAdjustment);

            $nonOriginal = $this->hasNonOriginalAlbumMarkers($candidateTitle);
            $demo = $this->hasDemoMarkers($candidateTitle);
            $compilation = $this->hasCompilationMarkers($candidateTitle);

            if ($prefersOriginalAlbum && $nonOriginal) {
                $score -= 0.28;
            }

            if ($prefersOriginalAlbum && $demo) {
                $score -= 0.12;
            }

            if ($prefersOriginalAlbum && $compilation) {
                $score -= 0.08;
            }

            if ($prefersOriginalAlbum && ! $exactMatch && $nonOriginal) {
                continue;
            }

            $candidates[] = [
                'album' => $album,
                'exact' => $exactMatch,
                'identity' => $identityMatch,
                'score' => $score,
                'non_original' => $nonOriginal,
                'demo' => $demo,
                'compilation' => $compilation,
                'prefer_original' => $prefersOriginalAlbum,
                'has_genius' => $album->genius_id !== null,
                'release_date' => $album->release_date?->format('Y-m-d'),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if (($left['exact'] ?? false) !== ($right['exact'] ?? false)) {
                return ($left['exact'] ?? false) ? -1 : 1;
            }

            if ((bool) ($left['prefer_original'] ?? false)) {
                if (($left['non_original'] ?? false) !== ($right['non_original'] ?? false)) {
                    return ((bool) ($left['non_original'] ?? false)) <=> ((bool) ($right['non_original'] ?? false));
                }

                if (($left['demo'] ?? false) !== ($right['demo'] ?? false)) {
                    return ((bool) ($left['demo'] ?? false)) <=> ((bool) ($right['demo'] ?? false));
                }

                if (($left['compilation'] ?? false) !== ($right['compilation'] ?? false)) {
                    return ((bool) ($left['compilation'] ?? false)) <=> ((bool) ($right['compilation'] ?? false));
                }
            }

            $leftReleaseDate = (string) ($left['release_date'] ?? '9999-12-31');
            $rightReleaseDate = (string) ($right['release_date'] ?? '9999-12-31');
            $releaseDateCompare = $leftReleaseDate <=> $rightReleaseDate;

            if ($releaseDateCompare !== 0) {
                return $releaseDateCompare;
            }

            $scoreCompare = (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0);

            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            if (($left['has_genius'] ?? false) !== ($right['has_genius'] ?? false)) {
                return ($left['has_genius'] ?? false) ? -1 : 1;
            }

            return ((int) ($left['album']->id ?? 0)) <=> ((int) ($right['album']->id ?? 0));
        });

        $bestCandidate = $candidates[0] ?? null;

        if (! is_array($bestCandidate) || ! ($bestCandidate['album'] ?? null) instanceof Album) {
            return null;
        }

        if (! ($bestCandidate['identity'] ?? false)) {
            return null;
        }

        return $bestCandidate['album'];
    }

    private function findLocalAlbumByReleaseDateFallback(Artist $primaryArtist, ?array $albumPayload): ?Album
    {
        $releaseDate = $this->normalizedAlbumPayloadReleaseDate($albumPayload);

        if ($releaseDate === null) {
            return null;
        }

        $candidates = collect($this->localAlbumsForArtist($primaryArtist))
            ->filter(fn (Album $album): bool => $album->release_date?->format('Y-m-d') === $releaseDate)
            ->values();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $withoutGeniusId = $candidates
            ->filter(fn (Album $album): bool => $album->genius_id === null)
            ->values();

        return $withoutGeniusId->count() === 1 ? $withoutGeniusId->first() : null;
    }

    private function normalizedAlbumPayloadReleaseDate(?array $albumPayload): ?string
    {
        if (! is_array($albumPayload)) {
            return null;
        }

        $year = (int) data_get($albumPayload, 'release_date_components.year', 0);
        $month = (int) data_get($albumPayload, 'release_date_components.month', 0);
        $day = (int) data_get($albumPayload, 'release_date_components.day', 0);

        if ($year > 0) {
            return sprintf('%04d-%02d-%02d', $year, max(1, $month), max(1, $day));
        }

        $releaseDate = (string) ($albumPayload['release_date'] ?? '');

        if ($releaseDate !== '' && preg_match('/\b(?<year>(?:19|20)\d{2})-(?<month>\d{1,2})-(?<day>\d{1,2})\b/', $releaseDate, $matches) === 1) {
            return sprintf(
                '%04d-%02d-%02d',
                (int) ($matches['year'] ?? 0),
                max(1, (int) ($matches['month'] ?? 1)),
                max(1, (int) ($matches['day'] ?? 1)),
            );
        }

        return null;
    }

    private function completeAlbumPayloadReleaseDate(array $payload): ?string
    {
        $date = (string) ($payload['release_date'] ?? '');

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches) === 1
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        $year = (int) data_get($payload, 'release_date_components.year', 0);
        $month = (int) data_get($payload, 'release_date_components.month', 0);
        $day = (int) data_get($payload, 'release_date_components.day', 0);

        return $year > 0 && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    private function albumComparableLookupKey(string $title): string
    {
        $storedTitle = $this->storedAlbumTitle($title);
        $normalized = $this->normalizeAlbumComparisonTitle($storedTitle);

        if ($normalized !== '') {
            return $normalized;
        }

        return GeniusNameMatcher::normalizeLoose($storedTitle);
    }

    private function storedAlbumTitle(?string $title): string
    {
        return GeniusNameMatcher::albumStorageValue((string) ($title ?? ''));
    }

    private function albumTitleSimilarity(string $left, string $right): float
    {
        $left = $this->storedAlbumTitle($left);
        $right = $this->storedAlbumTitle($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        return max(
            GeniusNameMatcher::score($left, $right),
            GeniusNameMatcher::score(
                $this->albumComparableLookupKey($left),
                $this->albumComparableLookupKey($right),
            ),
            $this->albumDigitSignatureScore($left, $right),
            $this->symbolicAlbumMatchScore($left, $right),
        );
    }

    private function albumDigitSignatureScore(string $left, string $right): float
    {
        $leftDigits = $this->albumDigitSignature($left);
        $rightDigits = $this->albumDigitSignature($right);

        if ($leftDigits === '' || $rightDigits === '' || $leftDigits !== $rightDigits || strlen($leftDigits) < 3) {
            return 0.0;
        }

        $rawScore = GeniusNameMatcher::score($left, $right);

        if ($rawScore < 0.42) {
            return 0.0;
        }

        $leftFlags = $this->versionFlags($left);
        $rightFlags = $this->versionFlags($right);

        if ($leftFlags !== [] && $rightFlags !== [] && array_intersect($leftFlags, $rightFlags) === []) {
            return 0.0;
        }

        return $leftFlags !== [] || $rightFlags !== [] ? 0.9 : 0.84;
    }

    private function albumDigitSignature(string $title): string
    {
        preg_match_all('/\d+/u', $this->storedAlbumTitle($title), $matches);

        return implode('', $matches[0] ?? []);
    }

    /**
     * @return array{0: Album, 1: bool}
     */
    private function upsertAlbumFallback(Artist $primaryArtist, string $albumTitle): array
    {
        $storedAlbumTitle = $this->storedAlbumTitle($albumTitle);
        $normalizedTitle = Str::lower($storedAlbumTitle);

        $album = Album::query()
            ->where('artist_id', $primaryArtist->id)
            ->whereRaw('LOWER(title) = ?', [$normalizedTitle])
            ->first();

        $created = false;

        if (! $album) {
            $album = Album::create(GeniusNameMatcher::sanitizeForDatabase([
                'artist_id' => $primaryArtist->id,
                'title' => $storedAlbumTitle,
                'slug' => $this->uniqueAlbumSlug(Str::slug($storedAlbumTitle) ?: 'album'),
                'cover_image_url' => null,
                'release_date' => null,
                'genius_id' => null,
            ]));

            $created = true;
        }

        if (method_exists($album, 'artists')) {
            $album->artists()->syncWithoutDetaching([$primaryArtist->id]);
        }

        return [$album, $created];
    }

    /**
     * @return array{0: Track, 1: bool}
     */
    private function upsertTrackFallback(Artist $primaryArtist, ?int $albumId, ParsedTrack $parsedTrack): array
    {
        $track = $this->findTrackByAudioIdentity($parsedTrack);
        $album = $albumId !== null ? Album::query()->find($albumId) : null;

        if ($album instanceof Album && $this->artistListTrackConflictsWithAlbumPageOccurrence(
            $album,
            $parsedTrack,
            $primaryArtist,
            $track,
        )) {
            $albumId = null;
            $album = null;
        }

        if (! $track) {
            $resolvedTrackNumber = $album instanceof Album
                ? $this->incomingTrackPositionForAlbum($parsedTrack, $album, null)
                : null;
            $owner = $this->findExistingOccurrenceOwner(null, $primaryArtist, $parsedTrack, $album, null, $resolvedTrackNumber);

            if ($owner instanceof Track) {
                if ($album instanceof Album
                    && (int) ($owner->genius_id ?? 0) > 0
                    && $this->albumHasOtherTrackWithGeniusId($album, (int) $owner->genius_id, $owner)) {
                    if ((int) ($owner->album_id ?? 0) === (int) $album->id) {
                        $owner->album_id = null;
                        $owner->track_number = null;
                    }
                    $albumId = null;
                    $album = null;
                }

                $alternateSources = (array) ($owner->muzofond_alternate_sources ?? []);
                $incomingPreferred = $this->incomingAlbumSourceIsPreferred($owner, $parsedTrack, $album);

                if ($incomingPreferred) {
                    $this->appendAlternateSource($alternateSources, $this->alternateSourceFromTrack($owner));
                    $incomingAudioId = MuzofondAudioIdentity::fileIdFromUrl($parsedTrack->audioUrl, $parsedTrack->audioFileId);
                    if ($incomingAudioId !== null) {
                        $owner->muzofond_audio_id = $incomingAudioId;
                    }
                    $owner->muzofond_title = GeniusNameMatcher::storageValue($parsedTrack->title);
                    $owner->muzofond_album_title = $parsedTrack->albumTitle !== null
                        ? $this->storedAlbumTitle($parsedTrack->albumTitle)
                        : $owner->muzofond_album_title;
                    $owner->original_link = $parsedTrack->audioUrl;

                    if (! (bool) $owner->is_downloaded) {
                        $owner->audio_url = $parsedTrack->audioUrl;
                    }
                } else {
                    $this->appendAlternateSource($alternateSources, $this->alternateSourceFromParsedTrack(
                        $parsedTrack,
                        null,
                        $album,
                        $resolvedTrackNumber,
                    ));
                }

                if ($owner->album_id === null && $albumId !== null) {
                    $owner->album_id = $albumId;
                }

                if ($this->trackHasColumn('muzofond_album_appearances')) {
                    $owner->muzofond_album_appearances = $this->mergeAlbumAppearanceLists(
                        (array) ($owner->muzofond_album_appearances ?? []),
                        $parsedTrack->albumAppearances,
                    );
                }

                if ($this->trackHasColumn('muzofond_alternate_sources')) {
                    $owner->muzofond_alternate_sources = $alternateSources;
                }

                $owner->save();
                $this->syncFallbackTrackAlbumMemberships($owner, $albumId, $parsedTrack);

                return [$owner, false];
            }
        }

        $created = false;

        if (! $track) {
            $created = true;
            $track = new Track();
        }

        $payload = GeniusNameMatcher::sanitizeForDatabase([
            'artist_id' => $primaryArtist->id,
            'album_id' => $albumId,
            'title' => GeniusNameMatcher::storageValue($parsedTrack->title),
            'duration_seconds' => $parsedTrack->durationSeconds,
            'audio_url' => $parsedTrack->audioUrl,
            'cover_image_url' => $albumId !== null ? null : ArtworkUrl::firstUsable(
                $track?->cover_image_url,
                $parsedTrack->albumCoverImageUrl,
            ),
            'release_year' => $parsedTrack->releaseYear,
            'track_number' => ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE ? $parsedTrack->trackNumber : null)
                ?? ($albumId !== null && (int) ($track?->album_id ?? 0) === (int) $albumId ? $track?->track_number : null),
            'genres' => $parsedTrack->genres !== [] ? array_values(array_unique($parsedTrack->genres)) : null,
            'genius_pageviews' => 0,
        ]);

        $audioFileId = MuzofondAudioIdentity::fileIdFromUrl($parsedTrack->audioUrl, $parsedTrack->audioFileId);

        if ($audioFileId !== null && $this->trackHasColumn('muzofond_audio_id')) {
            $payload['muzofond_audio_id'] = $audioFileId;
        }

        if ($this->trackHasColumn('muzofond_title')) {
            $payload['muzofond_title'] = GeniusNameMatcher::storageValue($parsedTrack->title);
            if ($parsedTrack->albumTitle !== null) {
                $payload['muzofond_album_title'] = $this->storedAlbumTitle($parsedTrack->albumTitle);
            }
        }

        if ($this->trackHasColumn('muzofond_album_appearances') && $parsedTrack->albumAppearances !== []) {
            $payload['muzofond_album_appearances'] = $this->mergedMuzofondAlbumAppearances($track, $parsedTrack);
        }

        if ($this->trackHasColumn('audio_version_ambiguous')) {
            $payload['audio_version_ambiguous'] = $this->sourceAudioVersionAmbiguous($parsedTrack);
        }

        if ($track->exists && (int) ($track->genius_id ?? 0) > 0
            && ($this->sourceAudioVersionAmbiguous($parsedTrack)
                || ! $this->songTitleMatchesParsedTrack($parsedTrack, ['title' => $track->title])
                || ($parsedTrack->albumTitle !== null
                    && $this->albumVersionFamily($parsedTrack->albumTitle) !== $this->albumVersionFamily($track->album?->title))
                || ($parsedTrack->albumTitle === null
                    && array_intersect(['remix', 'remixes'], $this->versionFlags($parsedTrack->title)) !== []
                    && $this->albumVersionFamily($track->album?->title) !== 'remix'))) {
            $payload['genius_id'] = null;
            $payload['genius_url'] = null;
            $payload['description_preview'] = null;
            $payload['genius_pageviews'] = 0;
        }

        if ($this->trackHasColumn('original_link')) {
            $payload['original_link'] = $track->original_link ?: $parsedTrack->audioUrl;
        }

        if (! $this->trackHasColumn('genius_pageviews')) {
            unset($payload['genius_pageviews']);
        }

        $payload = $this->preserveExistingAudioFields($track, $payload);

        $effectiveGeniusId = array_key_exists('genius_id', $payload)
            ? (int) ($payload['genius_id'] ?? 0)
            : (int) ($track->genius_id ?? 0);
        if ($album instanceof Album
            && $effectiveGeniusId > 0
            && $this->albumHasOtherTrackWithGeniusId($album, $effectiveGeniusId, $track)) {
            $blockedAlbumId = (int) $album->id;
            $existingAlbumId = (int) ($track->album_id ?? 0);
            $albumId = null;
            $payload['album_id'] = $existingAlbumId > 0 && $existingAlbumId !== $blockedAlbumId
                ? $existingAlbumId
                : null;
            $payload['track_number'] = $payload['album_id'] !== null ? $track->track_number : null;
        }

        $track->forceFill($payload)->save();
        $this->syncFallbackTrackAlbumMemberships($track, $albumId, $parsedTrack);

        return [$track, $created];
    }

    /** @return array<int, array{title: string, source_type: string, track_number: ?int}> */
    private function mergedMuzofondAlbumAppearances(Track $track, ParsedTrack $parsedTrack): array
    {
        $appearances = [];

        foreach (array_merge((array) ($track->muzofond_album_appearances ?? []), $parsedTrack->albumAppearances) as $appearance) {
            if (! is_array($appearance)) {
                continue;
            }

            $title = $this->storedAlbumTitle((string) ($appearance['title'] ?? ''));
            $key = GeniusNameMatcher::normalizeLoose($title);

            if ($key === '') {
                continue;
            }

            $candidate = [
                'title' => $title,
                'source_type' => (string) ($appearance['source_type'] ?? ParsedTrack::SOURCE_ARTIST_PAGE),
                'track_number' => isset($appearance['track_number']) ? (int) $appearance['track_number'] : null,
            ];

            if (! isset($appearances[$key])
                || ($candidate['source_type'] === ParsedTrack::SOURCE_ALBUM_PAGE
                    && $appearances[$key]['source_type'] !== ParsedTrack::SOURCE_ALBUM_PAGE)) {
                $appearances[$key] = $candidate;
            }
        }

        return array_values($appearances);
    }

    private function findTrackByAudioIdentity(ParsedTrack $parsedTrack): ?Track
    {
        $audioFileId = MuzofondAudioIdentity::fileIdFromUrl($parsedTrack->audioUrl, $parsedTrack->audioFileId);
        $audioUrls = array_values(array_unique(array_filter([
            trim($parsedTrack->audioUrl),
            MuzofondAudioIdentity::canonicalUrl($parsedTrack->audioUrl),
        ])));
        $hasAudioId = $this->trackHasColumn('muzofond_audio_id');

        if (($audioFileId === null || ! $hasAudioId) && $audioUrls === []) {
            return null;
        }

        $candidates = Track::query()
            ->where(function ($query) use ($audioFileId, $audioUrls, $hasAudioId): void {
                if ($hasAudioId && $audioFileId !== null) {
                    $query->orWhere('muzofond_audio_id', $audioFileId);
                }

                if ($audioUrls !== []) {
                    $query->orWhereIn('audio_url', $audioUrls);

                    if ($this->trackHasColumn('original_link')) {
                        $query->orWhereIn('original_link', $audioUrls);
                    }
                }
            })
            ->orderByDesc('genius_id')
            ->orderBy('id')
            ->get();

        return $candidates->first(fn (Track $track): bool => MuzofondAudioIdentity::matchesParsedTrack($track, $parsedTrack));
    }

    private function syncFallbackTrackAlbumMemberships(Track $track, ?int $albumId, ParsedTrack $parsedTrack): void
    {
        if (! method_exists($track, 'albums')) {
            return;
        }

        $sourceNumbers = $this->sourceAppearanceAlbumNumbers($track, $track->artist, $parsedTrack);
        $existingAlbumId = (int) ($track->album_id ?? 0);
        $albumIds = array_values(array_unique(array_filter(
            array_merge(
                $albumId !== null && $albumId > 0 ? [$albumId] : [],
                $existingAlbumId > 0 ? [$existingAlbumId] : [],
                array_keys($sourceNumbers),
            ),
            fn ($candidateAlbumId) => (int) $candidateAlbumId > 0,
        )));
        $geniusId = (int) ($track->genius_id ?? 0);
        $albumIds = array_values(array_filter($albumIds, function ($candidateAlbumId) use ($track, $geniusId): bool {
            $candidateAlbum = Album::query()->find((int) $candidateAlbumId);

            return ! ($candidateAlbum instanceof Album
                && $this->albumHasOtherTrackWithGeniusId($candidateAlbum, $geniusId, $track));
        }));

        if ($albumIds === []) {
            $track->albums()->sync([]);

            if ($existingAlbumId > 0) {
                $track->album_id = null;
                $track->track_number = null;
                $track->save();
            }

            return;
        }

        $payload = [];

        foreach ($albumIds as $linkedAlbumId) {
            $payload[$linkedAlbumId] = [
                'is_primary' => ($albumId !== null && $linkedAlbumId === $albumId)
                    || ($albumId === null && $linkedAlbumId === $existingAlbumId),
                'track_number' => ($albumId !== null && $linkedAlbumId === $albumId && $parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE
                    ? $parsedTrack->trackNumber : null)
                    ?? ($sourceNumbers[$linkedAlbumId] ?? null)
                    ?? DB::table('album_track')->where('album_id', $linkedAlbumId)->where('track_id', $track->id)->value('track_number'),
            ];
        }

        $track->albums()->sync($payload);
    }

    /** @return array<int, int|null> */
    private function sourceAppearanceAlbumNumbers(Track $track, Artist $artist, ParsedTrack $parsedTrack): array
    {
        if ($this->sourceAudioVersionAmbiguous($parsedTrack)) {
            return [];
        }

        $appearances = array_merge(
            (array) ($track->muzofond_album_appearances ?? []),
            $parsedTrack->albumAppearances,
        );
        $numbers = [];

        foreach ($appearances as $appearance) {
            if (! is_array($appearance) || ! $this->shouldPersistAlbumTitle($appearance['title'] ?? null)) {
                continue;
            }

            $title = (string) $appearance['title'];

            if ($this->versionFlags($parsedTrack->title) !== []
                && $this->versionFlags($title) === []
                && GeniusNameMatcher::canonicalTrack($title) === GeniusNameMatcher::canonicalTrack($parsedTrack->title)) {
                continue;
            }

            $album = $this->findBestLocalFallbackAlbumMatch($artist, $title);

            if (! $album instanceof Album
                || ! $this->albumMembershipHasUniqueVersion($album, $parsedTrack, $track, (int) ($track->genius_id ?? 0))) {
                continue;
            }

            $number = ($appearance['source_type'] ?? null) === ParsedTrack::SOURCE_ALBUM_PAGE
                ? (int) ($appearance['track_number'] ?? 0)
                : 0;
            $numbers[(int) $album->id] = $number > 0 ? $number : ($numbers[(int) $album->id] ?? null);
        }

        return $numbers;
    }

    private function reconcileCanonicalAlbumTrackMemberships(Artist $artist): void
    {
        $primaryAlbumIds = Track::query()
            ->where('artist_id', $artist->id)
            ->whereNotNull('album_id')
            ->distinct()
            ->pluck('album_id');
        $linkedAlbumIds = DB::table('album_track')
            ->join('tracks', 'tracks.id', '=', 'album_track.track_id')
            ->where('tracks.artist_id', $artist->id)
            ->distinct()
            ->pluck('album_track.album_id');
        $albumIds = $primaryAlbumIds->merge($linkedAlbumIds)->unique()->values();

        foreach ($albumIds as $albumId) {
            $album = Album::query()->find($albumId);

            if (! $album instanceof Album) {
                continue;
            }

            $members = $album->tracks()->with(['album', 'artists', 'albums'])->get();
            $canonical = $members->filter(fn (Track $track): bool => (int) ($track->genius_id ?? 0) > 0);

            if ($canonical->isEmpty()) {
                continue;
            }

            foreach ($members as $track) {
                if ((int) ($track->genius_id ?? 0) > 0
                    || (bool) $track->audio_version_ambiguous) {
                    continue;
                }

                $sourcePosition = $this->storedSourcePositionForAlbum($track, $album);
                $sourceAlbumTitle = $this->shouldPersistAlbumTitle($track->muzofond_album_title)
                    ? $this->storedAlbumTitle((string) $track->muzofond_album_title)
                    : (string) $album->title;
                $source = new ParsedTrack(
                    title: (string) ($track->muzofond_title ?: $track->title),
                    durationSeconds: (int) $track->duration_seconds,
                    audioUrl: (string) ($track->original_link ?: $track->audio_url),
                    albumTitle: $sourceAlbumTitle,
                    trackNumber: $sourcePosition,
                    artistNames: $track->artists->pluck('name')->values()->all(),
                    sourceType: $sourcePosition !== null ? ParsedTrack::SOURCE_ALBUM_PAGE : ParsedTrack::SOURCE_ARTIST_PAGE,
                    audioFileId: $track->muzofond_audio_id,
                    albumAppearances: (array) ($track->muzofond_album_appearances ?? []),
                );

                $owner = $canonical->first(fn (Track $candidate): bool =>
                    (int) $candidate->artist_id === (int) $artist->id
                    && $this->canConsolidateMatchedSource($candidate, $source, $artist, $album, $sourcePosition)
                    && $this->storedSourcePositionForAlbum($candidate, $album) === $sourcePosition
                );

                if ($owner instanceof Track) {
                    $alternateSources = (array) ($owner->muzofond_alternate_sources ?? []);

                    foreach ((array) ($track->muzofond_alternate_sources ?? []) as $alternateSource) {
                        if (is_array($alternateSource)) {
                            $this->appendAlternateSource($alternateSources, $alternateSource);
                        }
                    }

                    $sameAudio = array_intersect(
                        MuzofondAudioIdentity::keysForTrack($owner),
                        MuzofondAudioIdentity::keysForTrack($track),
                    ) !== [];

                    if (! $sameAudio) {
                        $this->appendAlternateSource($alternateSources, $this->alternateSourceFromTrack($track));
                    }

                    if ($this->trackHasColumn('muzofond_alternate_sources')) {
                        $owner->muzofond_alternate_sources = $alternateSources;
                        $owner->save();
                    }

                    $this->mergeStoredAlbumAppearances($owner, (array) ($track->muzofond_album_appearances ?? []));
                    $this->mergeDuplicateTrackRelations($owner, $track);
                    $members = $members->reject(fn (Track $member): bool => (int) $member->id === (int) $track->id);
                }
            }
        }
    }

    private function syncTrackAlbumMemberships(
        Track $track,
        ?Album $primaryAlbum,
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
    ): void {
        if (! method_exists($track, 'albums')) {
            return;
        }

        $songId = (int) ($songPayload['id'] ?? 0);

        $albumIds = [];

        if ($primaryAlbum instanceof Album && (int) $primaryAlbum->id > 0) {
            $albumIds[] = (int) $primaryAlbum->id;
        }

        foreach ($this->resolvedAdditionalTrackAlbums($primaryArtist, $parsedTrack, $songPayload, $primaryAlbum) as $album) {
            if ($album instanceof Album && (int) $album->id > 0
                && $this->albumMembershipHasUniqueVersion($album, $parsedTrack, $track, $songId)) {
                $albumIds[] = (int) $album->id;
            }
        }

        $sourceNumbers = $this->sourceAppearanceAlbumNumbers($track, $primaryArtist, $parsedTrack);
        $albumIds = array_merge($albumIds, array_keys($sourceNumbers));
        $existingAlbumId = (int) ($track->album_id ?? 0);
        if ($existingAlbumId > 0) {
            $albumIds[] = $existingAlbumId;
        }

        $albumIds = array_values(array_unique(array_filter($albumIds, fn ($albumId) => (int) $albumId > 0)));

        if ($parsedTrack->sourceType !== ParsedTrack::SOURCE_ALBUM_PAGE) {
            $albumIds = array_values(array_filter($albumIds, function ($albumId) use (
                $primaryAlbum,
                $track,
                $primaryArtist,
                $parsedTrack,
                $songId,
            ): bool {
                $albumId = (int) $albumId;
                $album = $primaryAlbum instanceof Album && (int) $primaryAlbum->id === $albumId
                    ? $primaryAlbum
                    : Album::query()->find($albumId);

                return ! ($album instanceof Album && $this->artistListTrackConflictsWithAlbumPageOccurrence(
                    $album,
                    $parsedTrack,
                    $primaryArtist,
                    $track,
                    $songId,
                ));
            }));
        }

        $albumIds = array_values(array_filter($albumIds, function ($albumId) use ($primaryAlbum, $track, $songId): bool {
            $albumId = (int) $albumId;
            $album = $primaryAlbum instanceof Album && (int) $primaryAlbum->id === $albumId
                ? $primaryAlbum
                : Album::query()->find($albumId);
            $geniusId = $songId > 0 ? $songId : (int) ($track->genius_id ?? 0);

            return ! ($album instanceof Album
                && $this->albumHasOtherTrackWithGeniusId($album, $geniusId, $track));
        }));

        $primaryAlbumId = $primaryAlbum instanceof Album && in_array((int) $primaryAlbum->id, $albumIds, true)
            ? (int) $primaryAlbum->id
            : ($existingAlbumId > 0 && in_array($existingAlbumId, $albumIds, true) ? $existingAlbumId : null);

        if ($existingAlbumId > 0 && ! in_array($existingAlbumId, $albumIds, true)) {
            $track->album_id = null;
            $track->track_number = null;
            $track->save();
        }

        if ($albumIds === []) {
            $track->albums()->sync([]);

            return;
        }

        $payload = [];
        $preservedPrimaryTrackNumber = $existingAlbumId > 0
            && $primaryAlbumId === $existingAlbumId
            && (int) ($track->track_number ?? 0) > 0
                ? (int) $track->track_number
                : null;

        foreach ($albumIds as $albumId) {
            $albumForNumber = $primaryAlbum instanceof Album && (int) $primaryAlbum->id === $albumId
                ? $primaryAlbum
                : Album::query()->find($albumId);
            $payload[$albumId] = [
                'is_primary' => $primaryAlbumId !== null && $albumId === $primaryAlbumId,
                'track_number' => ($albumId === $existingAlbumId ? $preservedPrimaryTrackNumber : null)
                    ?? ($albumForNumber instanceof Album
                    ? $this->albumMembershipTrackNumber($albumForNumber, $parsedTrack, $songPayload)
                    : null)
                    ?? ($sourceNumbers[$albumId] ?? null)
                    ?? DB::table('album_track')->where('album_id', $albumId)->where('track_id', $track->id)->value('track_number'),
            ];
        }

        $track->albums()->sync($payload);
    }

    private function albumMembershipTrackNumber(Album $album, ParsedTrack $parsedTrack, array $songPayload): ?int
    {
        if ($parsedTrack->sourceType === ParsedTrack::SOURCE_ALBUM_PAGE) {
            $albumPagePosition = $this->incomingTrackPositionForAlbum($parsedTrack, $album, null);

            if ($albumPagePosition !== null) {
                return $albumPagePosition;
            }
        } else {
            $sourceAlbumPosition = $this->parsedSourcePositionForAlbum($parsedTrack, (string) $album->title);

            if ($sourceAlbumPosition !== null) {
                return $sourceAlbumPosition;
            }
        }

        $geniusTrackNumber = $this->geniusAlbumTrackNumber($album, $songPayload);

        if ($geniusTrackNumber !== null) {
            return $geniusTrackNumber;
        }

        $albumGeniusId = (int) ($album->genius_id ?? 0);
        $songId = (int) ($songPayload['id'] ?? 0);

        if ($albumGeniusId <= 0 || $songId <= 0) {
            return null;
        }

        if ((int) data_get($songPayload, 'album.id', 0) === $albumGeniusId) {
            $direct = $this->directTrackNumberFromPayload($songPayload);

            if ($direct !== null) {
                return $direct;
            }
        }

        return null;
    }

    private function geniusAlbumTrackNumber(Album $album, array $songPayload): ?int
    {
        $albumGeniusId = (int) ($album->genius_id ?? 0);
        $songId = (int) ($songPayload['id'] ?? 0);

        if ($albumGeniusId <= 0 || $songId <= 0) {
            return null;
        }

        if ((int) data_get($songPayload, 'album.id', 0) === $albumGeniusId) {
            $directNumber = $this->directTrackNumberFromPayload($songPayload);

            if ($directNumber !== null) {
                return $directNumber;
            }
        }

        foreach ($this->albumTrackSongCache[$albumGeniusId] ?? [] as $index => $albumTrack) {
            if ((int) ($albumTrack['id'] ?? 0) === $songId) {
                return $this->directTrackNumberFromPayload($albumTrack) ?? ($index + 1);
            }
        }

        $albumUrl = data_get($songPayload, 'album.url');
        $trackNumbers = $this->geniusClient->albumTrackNumbers(
            $albumGeniusId,
            is_string($albumUrl) ? $albumUrl : null,
        );

        return isset($trackNumbers[$songId]) && (int) $trackNumbers[$songId] > 0 && (int) $trackNumbers[$songId] <= 80
            ? (int) $trackNumbers[$songId]
            : null;
    }

    /**
     * @return Album[]
     */
    private function resolvedAdditionalTrackAlbums(
        Artist $primaryArtist,
        ParsedTrack $parsedTrack,
        array $songPayload,
        ?Album $primaryAlbum = null,
    ): array {
        $sourceAlbumTitles = collect(array_merge(
            $this->parsedSourceAlbumTitle($parsedTrack) !== null ? [$this->parsedSourceAlbumTitle($parsedTrack)] : [],
            array_map(
                fn (array $appearance): string => $this->storedAlbumTitle((string) ($appearance['title'] ?? '')),
                array_filter($parsedTrack->albumAppearances, fn ($appearance): bool =>
                    is_array($appearance)
                    && $this->shouldPersistAlbumTitle($appearance['title'] ?? null)
                ),
            ),
        ))
            ->map(fn (string $title): string => GeniusNameMatcher::normalizeLoose($title))
            ->filter()
            ->unique()
            ->all();

        if ($sourceAlbumTitles === []) {
            return [];
        }

        $albumPayloads = [];

        foreach (array_merge(
            [is_array($songPayload['album'] ?? null) ? $songPayload['album'] : null],
            array_values(array_filter((array) ($songPayload['albums'] ?? []), fn ($albumPayload) => is_array($albumPayload)))
        ) as $albumPayload) {
            if (! is_array($albumPayload)) {
                continue;
            }

            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            $albumPayloads[$albumId] = $albumPayload;
        }

        $albums = [];

        foreach ($albumPayloads as $albumPayload) {
            $albumId = (int) ($albumPayload['id'] ?? 0);

            if ($albumId <= 0) {
                continue;
            }

            $payloadAlbumTitle = $this->extractAlbumPayloadTitle($albumPayload);
            $payloadAlbumKey = $payloadAlbumTitle !== null
                ? GeniusNameMatcher::normalizeLoose($this->storedAlbumTitle($payloadAlbumTitle))
                : '';

            if ($payloadAlbumKey === '' || ! in_array($payloadAlbumKey, $sourceAlbumTitles, true)) {
                continue;
            }

            if ($primaryAlbum instanceof Album && (int) ($primaryAlbum->genius_id ?? 0) === $albumId) {
                $albums[] = $primaryAlbum;

                continue;
            }

            $album = Album::query()
                ->where('genius_id', $albumId)
                ->first();

            if (! $album instanceof Album && $this->shouldCreateAlbumFromPayload($albumPayload)) {
                [$album] = $this->upsertAlbumFromGenius($primaryArtist, $albumPayload);
            }

            if ($album instanceof Album) {
                $albums[] = $album;
            }
        }

        return collect($albums)
            ->unique(fn (Album $album) => $album->id)
            ->values()
            ->all();
    }

    private function shouldPersistAlbumTitle(?string $albumTitle): bool
    {
        $storedAlbumTitle = $this->storedAlbumTitle((string) ($albumTitle ?? ''));

        if ($storedAlbumTitle === '') {
            return false;
        }

        $normalized = GeniusNameMatcher::normalizeLoose($storedAlbumTitle);

        if ($normalized === '') {
            return preg_match('/[\p{L}\p{N}\p{S}]/u', $storedAlbumTitle) === 1;
        }

        return ! in_array($normalized, [
            'novinki',
            'novinka',
            'new',
            'news',
            'popularnoe',
            'populiarnoe',
            'top',
            'bonustrack',
            'bonus track',
        ], true);
    }

    private function songAlbumMatchesParsedTrack(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        if ($this->sourceAlbumVersionConflictsWithSong($parsedTrack, $songPayload)) {
            return false;
        }

        if (! $this->shouldPersistAlbumTitle($parsedTrack->albumTitle)) {
            return true;
        }

        $songAlbumTitle = $this->extractSongAlbumTitle($songPayload);

        if ($songAlbumTitle === null) {
            return true;
        }

        if ($this->hasSingleLikeAlbumMarkers($songAlbumTitle, $parsedTrack->title)
            && ! $this->hasSingleLikeAlbumMarkers((string) $parsedTrack->albumTitle, $parsedTrack->title)
            && ! $this->albumTitlesShareIdentity((string) $parsedTrack->albumTitle, $songAlbumTitle)) {
            $otherAlbums = array_filter((array) ($songPayload['albums'] ?? []), fn ($album) => is_array($album));
            $sourceAlbumIsExplicit = collect($otherAlbums)->contains(fn (array $album): bool => $this->albumTitlesShareIdentity(
                (string) $parsedTrack->albumTitle,
                (string) ($this->extractAlbumPayloadTitle($album) ?? ''),
            ));

            if (! $sourceAlbumIsExplicit) {
                return false;
            }
        }

        return $this->albumMatchAdjustment(
            $parsedTrack->albumTitle,
            $songAlbumTitle,
            $parsedTrack->title,
            (string) ($songPayload['title'] ?? ''),
        ) > -0.16;
    }

    private function sourceAlbumVersionConflictsWithSong(ParsedTrack $parsedTrack, array $songPayload): bool
    {
        $sourceVersion = $this->albumVersionFamily($parsedTrack->albumTitle);

        if ($sourceVersion === null) {
            return $parsedTrack->albumTitle !== null
                && $this->albumVersionFamily($this->extractSongAlbumTitle($songPayload)) !== null;
        }

        return $sourceVersion !== $this->albumVersionFamily($this->extractSongAlbumTitle($songPayload))
            && $sourceVersion !== $this->albumVersionFamily((string) ($songPayload['title'] ?? ''));
    }

    private function extractSongAlbumTitle(array $songPayload): ?string
    {
        foreach (['album.name', 'album.title', 'album.full_title', 'album.display_title', 'release.name', 'release.title'] as $path) {
            $value = data_get($songPayload, $path);

            if (! is_string($value)) {
                continue;
            }

            $value = $this->storedAlbumTitle($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractAlbumPayloadTitle(array $albumPayload): ?string
    {
        foreach (['name', 'title', 'full_title', 'display_title'] as $path) {
            $value = data_get($albumPayload, $path);

            if (! is_string($value)) {
                continue;
            }

            $value = $this->storedAlbumTitle($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function compilationAlbumPenalty(string $muzofondAlbumTitle, string $geniusAlbumTitle): float
    {
        $normalizedMuzofond = $this->normalizeAlbumComparisonTitle($muzofondAlbumTitle);
        $normalizedGenius = $this->normalizeAlbumComparisonTitle($geniusAlbumTitle);

        if ($normalizedMuzofond === '' || $normalizedGenius === '' || $normalizedMuzofond === $normalizedGenius) {
            return 0.0;
        }

        $muzofondHasCompilationMarkers = $this->hasCompilationMarkers($muzofondAlbumTitle);
        $geniusHasCompilationMarkers = $this->hasCompilationMarkers($geniusAlbumTitle);
        $muzofondHasDemoMarkers = $this->hasDemoMarkers($muzofondAlbumTitle);
        $geniusHasDemoMarkers = $this->hasDemoMarkers($geniusAlbumTitle);
        $muzofondVersionFlags = $this->versionFlags($muzofondAlbumTitle);
        $geniusVersionFlags = $this->versionFlags($geniusAlbumTitle);
        $penalty = 0.0;

        if (! $muzofondHasCompilationMarkers && $geniusHasCompilationMarkers) {
            $penalty -= 0.24;
        }

        if (! $muzofondHasDemoMarkers && $geniusHasDemoMarkers) {
            $penalty -= 0.18;
        }

        if ($muzofondVersionFlags === []
            && array_intersect($geniusVersionFlags, ['live', 'remix', 'remixes', 'instrumental', 'karaoke', 'acoustic', 'edit', 'remaster', 'ringtone', 'slow', 'reverb']) !== []) {
            $penalty -= 0.42;
        }

        if ($muzofondVersionFlags === []
            && array_intersect($geniusVersionFlags, ['deluxe', 'ver2']) !== []) {
            $penalty -= 0.24;
        }

        if ($muzofondVersionFlags !== []
            && $geniusVersionFlags !== []
            && array_intersect($muzofondVersionFlags, $geniusVersionFlags) === []) {
            $penalty -= 0.18;
        }

        if ((str_contains($normalizedGenius, $normalizedMuzofond) || str_contains($normalizedMuzofond, $normalizedGenius))
            && $this->versionFlags($muzofondAlbumTitle) === []
            && $this->versionFlags($geniusAlbumTitle) === []) {
            $penalty -= 0.18;
        }

        return $penalty;
    }

    private function hasCompilationMarkers(string $title): bool
    {
        $normalized = GeniusNameMatcher::normalizeLoose($title);

        return preg_match('/\b(?:best of|greatest hits|hits|anthology|collection|collected|essentials|complete|selected|singles|compilation|box set|chapters|chronicles)\b/iu', $normalized) === 1;
    }

    private function hasDemoMarkers(string $title): bool
    {
        return preg_match('/\bdemo\b/iu', GeniusNameMatcher::normalizeLoose($title)) === 1;
    }

    private function hasUnreleasedMarkers(string $title): bool
    {
        return preg_match('/\bunreleased\b/iu', GeniusNameMatcher::normalizeLoose($title)) === 1;
    }

    private function hasSingleLikeAlbumMarkers(string $title, ?string $trackTitle = null): bool
    {
        $storedTitle = $this->storedAlbumTitle($title);

        if ($storedTitle === '') {
            return false;
        }

        $normalized = GeniusNameMatcher::normalizeLoose($storedTitle);

        if (preg_match('/\b(?:single|ep)\b/iu', $normalized) === 1) {
            return true;
        }

        if ($trackTitle === null || $trackTitle === '') {
            return false;
        }

        return GeniusNameMatcher::score(
            GeniusNameMatcher::canonicalTrack($storedTitle),
            GeniusNameMatcher::canonicalTrack($trackTitle),
        ) >= 0.96;
    }

    private function hasNonOriginalAlbumMarkers(string $title): bool
    {
        return array_intersect($this->versionFlags($title), [
            'live',
            'remix',
            'remixes',
            'instrumental',
            'karaoke',
            'demo',
            'acoustic',
            'edit',
            'deluxe',
            'ver2',
            'remaster',
            'ringtone',
            'slow',
            'reverb',
        ]) !== [];
    }

    private function releaseYearMatchAdjustment(?int $muzofondReleaseYear, array $payload): float
    {
        if (! $muzofondReleaseYear) {
            return 0.0;
        }

        $geniusReleaseYear = (int) data_get($payload, 'release_date_components.year', 0);

        if ($geniusReleaseYear <= 0 && preg_match('/\b(19|20)\d{2}\b/', (string) ($payload['release_date'] ?? ''), $matches) === 1) {
            $geniusReleaseYear = (int) $matches[0];
        }

        if ($geniusReleaseYear <= 0 && preg_match('/\b(19|20)\d{2}\b/', (string) data_get($payload, 'album.release_date', ''), $matches) === 1) {
            $geniusReleaseYear = (int) $matches[0];
        }

        if ($geniusReleaseYear <= 0) {
            return 0.0;
        }

        $delta = abs($muzofondReleaseYear - $geniusReleaseYear);

        return match (true) {
            $delta === 0 => 0.12,
            $delta === 1 => 0.06,
            $delta === 2 => -0.03,
            $delta <= 4 => -0.12,
            default => -0.24,
        };
    }

    private function albumMatchAdjustment(?string $muzofondAlbumTitle, ?string $geniusAlbumTitle, string $muzofondTitle, string $geniusTitle): float
    {
        if (! $this->shouldPersistAlbumTitle($muzofondAlbumTitle)) {
            return 0.0;
        }

        $muzofondAlbumTitle = $this->storedAlbumTitle((string) $muzofondAlbumTitle);
        $geniusAlbumTitle = $this->storedAlbumTitle((string) ($geniusAlbumTitle ?? ''));

        if ($muzofondAlbumTitle === '') {
            return 0.0;
        }

        if ($geniusAlbumTitle === '') {
            return -0.08;
        }

        if ($this->isWeakAlbumTitleSignal($geniusAlbumTitle) && ! $this->isWeakAlbumTitleSignal($muzofondAlbumTitle)) {
            return 0.04;
        }

        $baseScore = $this->albumTitleSimilarity($muzofondAlbumTitle, $geniusAlbumTitle);

        $versionScore = $this->versionMatchAdjustment(
            $muzofondAlbumTitle . ' ' . $muzofondTitle,
            $geniusAlbumTitle . ' ' . $geniusTitle,
        );
        $compilationPenalty = $this->compilationAlbumPenalty($muzofondAlbumTitle, $geniusAlbumTitle);

        if ($baseScore >= 0.96) {
            return 0.22 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.88) {
            return 0.14 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.76) {
            return 0.02 + $versionScore + $compilationPenalty;
        }

        if ($baseScore >= 0.62) {
            return -0.10 + $versionScore + $compilationPenalty;
        }

        return -0.28 + $versionScore + $compilationPenalty;
    }

    private function normalizeAlbumComparisonTitle(string $value): string
    {
        $value = $this->unwrapLeadingSymbolicAlbumPrefix($this->storedAlbumTitle($value));
        $value = $this->normalizeRomanNumeralsInAlbumTitle($value);
        $value = preg_replace('/\b(?:live|remix(?:es)?|mix(?:es)?|instrumental|karaoke|demo|acoustic|radio\s+edit|edit|deluxe|expanded|bonus|ep|single|version|edition|remaster(?:ed)?|ring(?: ?tone)?|caller\s+tune)\b/iu', ' ', $value) ?? $value;
        $value = str_replace(['(', ')', '[', ']', '{', '}'], ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
        $normalized = GeniusNameMatcher::normalizeLoose($value);

        if ($normalized !== '') {
            return $normalized;
        }

        return $this->normalizeSymbolicAlbumTitle($value);
    }

    private function isWeakAlbumTitleSignal(string $title): bool
    {
        $storedTitle = $this->storedAlbumTitle($title);

        if ($storedTitle === '') {
            return true;
        }

        $normalized = GeniusNameMatcher::normalizeLoose($storedTitle);

        if ($normalized === '' || mb_strlen($normalized) < 4) {
            return true;
        }

        return collect([
            'Р ',
            'РЎ',
            'Гѓ',
            'Г‚',
            'Гў',
            'Гђ',
            'Г‘',
            'РІР‚',
            'РІв‚¬',
        ])->contains(fn (string $marker) => str_contains($storedTitle, $marker));
    }

    private function normalizeRomanNumeralsInAlbumTitle(string $value): string
    {
        return preg_replace_callback('/\b(?<roman>m{0,4}(cm|cd|d?c{0,3})(xc|xl|l?x{0,3})(ix|iv|v?i{1,3}))\b/iu', function (array $matches): string {
            $roman = Str::upper((string) ($matches['roman'] ?? ''));

            if ($roman === '') {
                return '';
            }

            $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
            $total = 0;
            $previous = 0;

            foreach (array_reverse(str_split($roman)) as $symbol) {
                $value = $map[$symbol] ?? 0;

                if ($value <= 0) {
                    return $roman;
                }

                if ($value < $previous) {
                    $total -= $value;
                } else {
                    $total += $value;
                    $previous = $value;
                }
            }

            return $total > 0 ? (string) $total : $roman;
        }, $value) ?? $value;
    }

    private function normalizeSymbolicAlbumTitle(string $value): string
    {
        $value = $this->unwrapLeadingSymbolicAlbumPrefix($this->storedAlbumTitle($value));
        $value = Str::lower($value);
        $value = str_replace(['ё'], ['е'], $value);
        $value = preg_replace('/[^\p{L}\p{N}\p{S}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function unwrapLeadingSymbolicAlbumPrefix(string $value): string
    {
        $value = $this->storedAlbumTitle($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(?<prefix>[^()]+?)\s*\((?<inner>.+)\)$/u', $value, $matches) !== 1) {
            return $value;
        }

        $prefix = trim((string) ($matches['prefix'] ?? ''));
        $inner = trim((string) ($matches['inner'] ?? ''));

        if ($prefix === '' || $inner === '' || preg_match('/[\p{L}\p{N}]/u', $inner) !== 1) {
            return $value;
        }

        if ($this->looksLikeBrokenAlbumSymbolPrefix($prefix) || preg_match('/^\p{S}+$/u', $prefix) === 1) {
            return $inner;
        }

        return $value;
    }

    private function looksLikeBrokenAlbumSymbolPrefix(string $value): bool
    {
        $normalized = GeniusNameMatcher::normalizeLoose($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['riv s', 'ri v s', 'rv s', 'v s', 'ri v', 'riv'], true)) {
            return true;
        }

        return preg_match('/^(?:r|ri|riv|v|s)(?:\s+(?:r|ri|riv|v|s))*$/u', $normalized) === 1;
    }

    private function symbolicAlbumMatchScore(string $muzofondAlbumTitle, string $geniusAlbumTitle): float
    {
        $muzofondSymbols = $this->meaningfulAlbumSymbols($muzofondAlbumTitle);
        $geniusSymbols = $this->meaningfulAlbumSymbols($geniusAlbumTitle);

        if ($muzofondSymbols === [] || $geniusSymbols === []) {
            return 0.0;
        }

        return array_intersect($muzofondSymbols, $geniusSymbols) !== [] ? 0.98 : 0.0;
    }

    /**
     * @return string[]
     */
    private function meaningfulAlbumSymbols(string $value): array
    {
        $value = $this->storedAlbumTitle($value);

        if ($value === '' || preg_match_all('/\p{S}+/u', $value, $matches) !== 1) {
            return [];
        }

        return collect($matches[0] ?? [])
            ->map(fn (string $symbol) => trim($symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function versionMatchAdjustment(string $muzofondTitle, string $geniusTitle): float
    {
        $muzofondFlags = $this->versionFlags($muzofondTitle);
        $geniusFlags = $this->versionFlags($geniusTitle);
        $muzofondHasOnlyDemo = in_array('demo', $muzofondFlags, true)
            && array_values(array_diff($muzofondFlags, ['demo'])) === [];
        $geniusHasOnlyDemo = in_array('demo', $geniusFlags, true)
            && array_values(array_diff($geniusFlags, ['demo'])) === [];

        if ($muzofondFlags === [] && $geniusFlags === []) {
            return 0.0;
        }

        if ($muzofondHasOnlyDemo && $geniusFlags === []) {
            return 0.08;
        }

        if ($muzofondFlags === [] && $geniusHasOnlyDemo) {
            return -0.12;
        }

        if ($muzofondHasOnlyDemo && $geniusHasOnlyDemo) {
            return -0.02;
        }

        if ($muzofondFlags === [] && $geniusFlags !== []) {
            return array_intersect($geniusFlags, ['instrumental', 'karaoke', 'live', 'remix', 'remixes', 'ep', 'edit', 'extended', 'ringtone', 'slow', 'reverb']) !== []
                ? -0.22
                : -0.08;
        }

        if ($muzofondFlags !== [] && $geniusFlags === []) {
            if (array_intersect($muzofondFlags, ['live', 'instrumental', 'karaoke', 'remix', 'remixes', 'acoustic', 'edit', 'extended', 'slow', 'reverb']) !== []) {
                return -0.24;
            }

            return array_intersect($muzofondFlags, ['ringtone']) !== []
                ? -0.28
                : -0.14;
        }

        $intersection = array_values(array_intersect($muzofondFlags, $geniusFlags));

        if ($intersection === []) {
            return -0.24;
        }

        if (count($intersection) === count($muzofondFlags) && count($intersection) === count($geniusFlags)) {
            return 0.10;
        }

        return 0.02;
    }

    /**
     * @return string[]
     */
    private function versionFlags(string $title): array
    {
        $normalized = GeniusNameMatcher::normalizeLoose($title);
        $flags = [];

        $map = [
            'instrumental' => ['instrumental', 'instr', 'instrumentalnaia', 'instrumentalnaya', 'minus'],
            'remixes' => ['remixes'],
            'remix' => ['remix', 'remiks', 'rmx', 'dj mix', 'club mix', 'mix', 'miks'],
            'live' => ['live', 'concert', 'concertnaia', 'concertnaya'],
            'demo' => ['demo'],
            'acoustic' => ['acoustic', 'akustika', 'acustic'],
            'karaoke' => ['karaoke'],
            'slow' => ['slow', 'slowed'],
            'reverb' => ['reverb', 'reverbed', 'reverved'],
            'edit' => ['radio edit', 'edit'],
            'extended' => ['extended', 'extended mix', 'extended version'],
            'ep' => [' ep ', 'e p', 'extended play'],
            'deluxe' => ['deluxe', 'expanded', 'bonus edition'],
            'remaster' => ['remaster', 'remastered'],
            'ver2' => ['ver 2', 'ver2', 'version 2', 'version2', 'versiia 2', 'versiya 2'],
            'ringtone' => ['ringtone', 'ring tone', 'rington', 'rington', 'caller tune', 'na zvonok'],
        ];

        foreach ($map as $flag => $needles) {
            foreach ($needles as $needle) {
                $normalizedNeedle = trim($needle);

                if ($normalizedNeedle !== ''
                    && str_contains(' ' . $normalized . ' ', ' ' . $normalizedNeedle . ' ')) {
                    $flags[] = $flag;
                    break;
                }
            }
        }

        return array_values(array_unique($flags));
    }

    private function isUniqueConstraintViolation(QueryException $exception, string $constraint): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, $constraint)
            || (str_contains($message, 'duplicate key value violates unique constraint')
                && str_contains($message, '"' . $constraint . '"'));
    }

    private function cleanupEmptyAlbumsForArtist(Artist $artist): void
    {
        $query = Album::query()->doesntHave('tracks')->doesntHave('primaryTracks');

        if (method_exists(Album::class, 'artists')) {
            $query->where(function ($subQuery) use ($artist): void {
                $subQuery
                    ->where('artist_id', $artist->id)
                    ->orWhereHas('artists', fn ($artistQuery) => $artistQuery->whereKey($artist->id));
            });
        } else {
            $query->where('artist_id', $artist->id);
        }

        $query->delete();
    }

    private function uniqueArtistSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Artist::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function uniqueArtistSlugForExisting(string $baseSlug, ?int $ignoreArtistId = null): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Artist::query()
            ->when($ignoreArtistId, fn ($query) => $query->whereKeyNot($ignoreArtistId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function uniqueAlbumSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Album::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function trackHasColumn(string $column): bool
    {
        if ($this->trackColumns === null) {
            $this->trackColumns = Schema::getColumnListing((new Track())->getTable());
        }

        return in_array($column, $this->trackColumns, true);
    }

}
