<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackGeniusController extends Controller
{
    public function __invoke(Track $track, GeniusClient $geniusClient): JsonResponse
    {
        if (! $track->genius_id) {
            return response()->json([
                'song' => null,
                'artists' => [],
            ]);
        }

        try {
            $song = $geniusClient->song((int) $track->genius_id);

            if (! $song) {
                return response()->json([
                    'song' => null,
                    'artists' => [],
                ]);
            }

            $artistPayloads = collect(array_merge(
                (array) ($song['primary_artists'] ?? []),
                (array) ($song['featured_artists'] ?? []),
            ))
                ->filter(fn ($artist) => is_array($artist) && isset($artist['id']))
                ->unique(fn ($artist) => (int) $artist['id'])
                ->take(8)
                ->values();

            $artists = $artistPayloads
                ->map(function (array $artistSummary) use ($geniusClient): ?array {
                    $artist = $geniusClient->artist((int) $artistSummary['id']);

                    if (! $artist) {
                        return null;
                    }

                    return [
                        'id' => (int) $artist['id'],
                        'name' => GeniusNameMatcher::storageValue((string) ($artist['name'] ?? '')),
                        'image_url' => $geniusClient->artistImageUrl($artist),
                        'description_preview' => $artist['description_preview'] ?? null,
                        'social_links' => array_filter((array) ($artist['social_links'] ?? [])),
                        'url' => $artist['url'] ?? null,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return response()->json([
                'song' => [
                    'id' => (int) ($song['id'] ?? 0),
                    'title' => GeniusNameMatcher::normalizeStoredTrackTitle((string) ($song['title'] ?? $track->title)),
                    'description_preview' => $song['description_preview'] ?? null,
                    'language' => GeniusNameMatcher::resolveLanguageCode((array) ($song['tags'] ?? []), $song['language'] ?? null),
                    'genres' => GeniusNameMatcher::extractGenres((array) ($song['tags'] ?? [])),
                    'release_date' => $song['release_date'] ?? null,
                    'is_single' => ! is_array($song['album'] ?? null),
                    'cover_image_url' => $song['song_art_image_url'] ?? $song['header_image_url'] ?? null,
                    'url' => $song['url'] ?? null,
                    'album' => is_array($song['album'] ?? null)
                        ? [
                            'id' => (int) ($song['album']['id'] ?? 0),
                            'title' => GeniusNameMatcher::storageValue((string) ($song['album']['name'] ?? $song['album']['title'] ?? '')),
                        ]
                        : null,
                ],
                'artists' => $artists,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Track genius runtime fetch failed.', [
                'track_id' => $track->id,
                'genius_id' => $track->genius_id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'song' => null,
                'artists' => [],
            ]);
        }
    }
}
