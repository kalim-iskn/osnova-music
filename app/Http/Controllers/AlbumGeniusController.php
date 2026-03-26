<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlbumGeniusController extends Controller
{
    public function __invoke(Album $album, GeniusClient $geniusClient): JsonResponse
    {
        if (! $album->genius_id) {
            return response()->json(['album' => null]);
        }

        try {
            $payload = $geniusClient->album((int) $album->genius_id);

            if (! $payload) {
                return response()->json(['album' => null]);
            }

            return response()->json([
                'album' => [
                    'id' => (int) ($payload['id'] ?? 0),
                    'title' => GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $album->title)),
                    'description_preview' => $payload['description_preview'] ?? null,
                    'cover_image_url' => $payload['cover_art_thumbnail_url'] ?? $payload['cover_art_url'] ?? null,
                    'release_date' => $payload['release_date_for_display'] ?? $payload['release_date'] ?? null,
                    'url' => $payload['url'] ?? null,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Album genius runtime fetch failed.', [
                'album_id' => $album->id,
                'genius_id' => $album->genius_id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['album' => null]);
        }
    }
}
