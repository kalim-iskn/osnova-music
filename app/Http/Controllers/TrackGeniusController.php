<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use Illuminate\Http\JsonResponse;

class TrackGeniusController extends Controller
{
    public function __invoke(Track $track, GeniusClient $geniusClient): JsonResponse
    {
        abort_unless($track->genius_id, 404, 'Для этого трека ещё не сохранён Genius ID.');

        $song = $geniusClient->song((int) $track->genius_id);

        if (! $song) {
            abort(404, 'Не удалось получить данные трека из Genius.');
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

        $album = is_array($song['album'] ?? null)
            ? [
                'id' => (int) $song['album']['id'],
                'title' => GeniusNameMatcher::storageValue((string) ($song['album']['name'] ?? $song['album']['title'] ?? '')),
                'cover_image_url' => $song['album']['cover_art_thumbnail_url']
                    ?? $song['album']['cover_art_url']
                    ?? null,
                'release_date' => $song['album']['release_date'] ?? null,
                'url' => $song['album']['url'] ?? null,
            ]
            : null;

        return response()->json([
            'song' => [
                'id' => (int) $song['id'],
                'title' => GeniusNameMatcher::storageValue((string) ($song['title'] ?? $track->title)),
                'description_preview' => $song['description_preview'] ?? null,
                'language' => GeniusNameMatcher::resolveLanguageCode((array) ($song['tags'] ?? []), $song['language'] ?? null),
                'genres' => GeniusNameMatcher::extractGenres((array) ($song['tags'] ?? [])),
                'release_date' => $song['release_date'] ?? null,
                'is_single' => $album === null,
                'cover_image_url' => $album ? null : ($song['song_art_image_url'] ?? $song['header_image_url'] ?? null),
                'url' => $song['url'] ?? null,
                'youtube_url' => $song['youtube_url'] ?? null,
                'soundcloud_url' => $song['soundcloud_url'] ?? null,
                'spotify_uuid' => $song['spotify_uuid'] ?? null,
                'apple_music_player_url' => $song['apple_music_player_url'] ?? null,
                'embed_content' => $song['embed_content'] ?? null,
                'album' => $album,
            ],
            'artists' => $artists,
        ]);
    }
}
