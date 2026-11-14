<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class FindMissingTrackAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $trackId) {}

    public function handle(MuzofondTrackParser $parser, GeniusCatalogSyncService $matcher): void
    {
        $track = Track::query()->with(['artist', 'artists', 'album'])->find($this->trackId);
        if (! $track || trim((string) $track->audio_url) !== '' || ! $track->artist) {
            return;
        }
        $candidates = $parser->searchSongCandidates($track->title, $track->artist->name);
        $source = $matcher->selectMissingTrackAudio($track, $candidates);
        if ($source === null) {
            return;
        }
        DB::transaction(function () use ($track, $source): void {
            // Serialize jobs attempting to claim the same Muzofond media file.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['waveflow-audio:'.($source->audioFileId ?: $source->audioUrl)]);
            }
            $locked = Track::query()->whereKey($track->id)->lockForUpdate()->first();
            if (! $locked || trim((string) $locked->audio_url) !== '' || (int) $locked->genius_id !== (int) $track->genius_id
                || (int) $locked->album_id !== (int) $track->album_id || (string) $locked->title !== (string) $track->title
                || $locked->artists()->pluck('artists.id')->sort()->values()->all() !== $track->artists->pluck('id')->sort()->values()->all()) {
                return;
            }
            $used = Track::query()->whereKeyNot($track->id)->where(function ($query) use ($source): void {
                $query->where('audio_url', $source->audioUrl)->orWhere('original_link', $source->audioUrl);
                if ($source->audioFileId !== null) {
                    $query->orWhere('muzofond_audio_id', $source->audioFileId);
                    if (DB::getDriverName() === 'sqlite') {
                        $query->orWhereRaw("EXISTS (SELECT 1 FROM json_each(tracks.muzofond_alternate_sources) WHERE json_extract(json_each.value, '$.audio_file_id') = ?)", [$source->audioFileId]);
                    } else {
                        $query->orWhereJsonContains('muzofond_alternate_sources', [['audio_file_id' => $source->audioFileId]]);
                    }
                }
            })->exists();
            if ($used) {
                return;
            }
            $locked->forceFill([
                'audio_url' => $source->audioUrl,
                'original_link' => $source->audioUrl,
                'duration_seconds' => $source->durationSeconds,
                'muzofond_audio_id' => $source->audioFileId,
                'muzofond_title' => $source->title,
                'muzofond_album_title' => $source->albumTitle,
                'muzofond_album_appearances' => $source->albumAppearances,
            ])->save();
        });
    }
}
