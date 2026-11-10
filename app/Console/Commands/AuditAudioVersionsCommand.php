<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\SourceAudioVersionAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditAudioVersionsCommand extends Command
{
    protected $signature = 'tracks:audit-audio-versions
        {--artist-id=* : Limit the audit to local artist IDs}
        {--apply : Remove unsupported Genius matches and album memberships}';

    protected $description = 'Find unlabeled audio versions whose duration contradicts the dominant recording';

    public function handle(SourceAudioVersionAnalyzer $analyzer): int
    {
        $artistIds = collect((array) $this->option('artist-id'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $apply = (bool) $this->option('apply');
        $total = 0;
        $changed = 0;

        Artist::query()
            ->when($artistIds !== [], fn ($query) => $query->whereIn('id', $artistIds))
            ->whereHas('tracks')
            ->orderBy('id')
            ->chunkById(100, function ($artists) use ($analyzer, $apply, &$total, &$changed): void {
                foreach ($artists as $artist) {
                    $tracks = Track::query()->where('artist_id', $artist->id)->orderBy('id')->get();
                    $byIdentity = [];
                    $parsed = [];

                    foreach ($tracks as $track) {
                        $source = new ParsedTrack(
                            title: (string) ($track->muzofond_title ?: $track->title),
                            durationSeconds: (int) $track->duration_seconds,
                            audioUrl: (string) ($track->original_link ?: $track->audio_url),
                            albumTitle: $track->muzofond_album_title,
                            audioFileId: $track->muzofond_audio_id,
                        );
                        $identity = $analyzer->identity($source);

                        if ($identity === null) {
                            continue;
                        }

                        $byIdentity[$identity] = $track;
                        $parsed[] = $source;
                    }

                    foreach ($analyzer->ambiguousFiles($parsed) as $identity => $evidence) {
                        $track = $byIdentity[$identity] ?? null;

                        if (! $track instanceof Track) {
                            continue;
                        }

                        $total++;
                        $needsChange = $track->genius_id !== null
                            || $track->album_id !== null
                            || $track->albums()->exists()
                            || ! $track->audio_version_ambiguous
                            || $track->title !== ($track->muzofond_title ?: $track->title);

                        $this->line(sprintf(
                            '%s artist %d, track %d, audio %s: %s; %d s vs reference %d s%s%s',
                            $apply ? '[apply]' : '[dry-run]',
                            $artist->id,
                            $track->id,
                            $track->muzofond_audio_id ?: '-',
                            $track->muzofond_title ?: $track->title,
                            $evidence['duration'],
                            $evidence['reference_duration'],
                            $evidence['near_named_version'] ? '; near named version' : '',
                            $needsChange ? '' : '; already isolated',
                        ));

                        if (! $apply || ! $needsChange) {
                            continue;
                        }

                        DB::transaction(function () use ($track): void {
                            $track->forceFill([
                                'title' => (string) ($track->muzofond_title ?: $track->title),
                                'genius_id' => null,
                                'genius_url' => null,
                                'description_preview' => null,
                                'genius_pageviews' => 0,
                                'album_id' => null,
                                'cover_image_url' => null,
                                'language' => null,
                                'release_year' => null,
                                'genres' => null,
                                'track_number' => null,
                                'audio_version_ambiguous' => true,
                            ])->save();
                            $track->albums()->sync([]);
                        });
                        $changed++;
                    }
                }
            });

        $this->info(sprintf('Ambiguous audio files: %d; changed: %d.', $total, $changed));

        return self::SUCCESS;
    }
}
