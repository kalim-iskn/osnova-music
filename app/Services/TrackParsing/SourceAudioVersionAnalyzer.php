<?php

namespace App\Services\TrackParsing;

use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\DTO\ParsedTrack;

/**
 * Find unlabeled source files whose duration contradicts the dominant recording
 * for the same song. This is a confidence check, not a remix classifier.
 */
final class SourceAudioVersionAnalyzer
{
    public function hasVersionMarker(string $title): bool
    {
        $title = GeniusNameMatcher::normalizeLoose($title);

        return preg_match('/\b(?:remix(?:es)?|remiks|rmx|mix|miks|live|laiv|acoustic|akustika|instrumental|karaoke|demo|extended|edit|remaster(?:ed)?|slow(?:ed)?|reverb(?:ed)?|nightcore|ringtone)\b/u', $title) === 1;
    }

    /**
     * @param  ParsedTrack[]  $tracks
     * @return array<string, array{title:string, duration:int, reference_duration:int, difference:int, near_named_version:bool}>
     */
    public function ambiguousFiles(array $tracks): array
    {
        $byIdentity = [];

        foreach ($tracks as $track) {
            if (! $track instanceof ParsedTrack || $track->durationSeconds < 30) {
                continue;
            }

            $identity = $this->identity($track);

            if ($identity === null) {
                continue;
            }

            $previous = $byIdentity[$identity] ?? null;

            if (! $previous instanceof ParsedTrack
                || ($this->hasVersionMarker($track->title) && ! $this->hasVersionMarker($previous->title))) {
                $byIdentity[$identity] = $track;
            }
        }

        $groups = [];

        foreach ($byIdentity as $identity => $track) {
            $title = GeniusNameMatcher::canonicalTrack($track->title);

            if ($title !== '') {
                $groups[$title][$identity] = $track;
            }
        }

        $ambiguous = [];

        foreach ($groups as $title => $group) {
            $plain = array_filter($group, fn (ParsedTrack $track): bool => ! $this->hasVersionMarker($track->title));
            $namedDurations = array_map(
                fn (ParsedTrack $track): int => $track->durationSeconds,
                array_values(array_filter($group, fn (ParsedTrack $track): bool => $this->hasVersionMarker($track->title))),
            );
            $reference = count($plain) >= 3 ? $this->dominantDuration(array_values($plain)) : null;

            foreach ($plain as $identity => $track) {
                $nearNamedVersion = collect($namedDurations)
                    ->contains(fn (int $duration): bool => abs($track->durationSeconds - $duration) <= 8);
                $comparisonDuration = $reference;

                if ($comparisonDuration === null && $nearNamedVersion) {
                    foreach ($plain as $otherIdentity => $otherTrack) {
                        if ($otherIdentity !== $identity
                            && abs($otherTrack->durationSeconds - $track->durationSeconds) > max(18, (int) round($otherTrack->durationSeconds * 0.10))) {
                            $comparisonDuration = $otherTrack->durationSeconds;
                            break;
                        }
                    }
                }

                if ($comparisonDuration === null) {
                    continue;
                }

                $difference = abs($track->durationSeconds - $comparisonDuration);

                if ($difference <= max(18, (int) round($comparisonDuration * 0.10))) {
                    continue;
                }

                $ambiguous[$identity] = [
                    'title' => $title,
                    'duration' => $track->durationSeconds,
                    'reference_duration' => $comparisonDuration,
                    'difference' => $difference,
                    'near_named_version' => $nearNamedVersion,
                ];
            }
        }

        return $ambiguous;
    }

    public function identity(ParsedTrack $track): ?string
    {
        return MuzofondAudioIdentity::keys($track->audioUrl, $track->audioFileId)[0] ?? null;
    }

    /** @param  ParsedTrack[]  $tracks */
    private function dominantDuration(array $tracks): ?int
    {
        $durations = array_map(fn (ParsedTrack $track): int => $track->durationSeconds, $tracks);
        $best = [];

        foreach ($durations as $duration) {
            $cluster = array_values(array_filter($durations, fn (int $candidate): bool => abs($candidate - $duration) <= 8));

            if (count($cluster) > count($best)
                || (count($cluster) === count($best) && $cluster !== []
                    && max($cluster) - min($cluster) < max($best) - min($best))) {
                $best = $cluster;
            }
        }

        if (count($best) < 2) {
            return null;
        }

        sort($best);

        return (int) round(($best[intdiv(count($best) - 1, 2)] + $best[intdiv(count($best), 2)]) / 2);
    }
}
