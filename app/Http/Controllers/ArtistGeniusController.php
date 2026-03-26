<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Services\Genius\GeniusClient;
use App\Services\Genius\GeniusNameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ArtistGeniusController extends Controller
{
    public function __invoke(Artist $artist, GeniusClient $geniusClient): JsonResponse
    {
        if (! $artist->genius_id) {
            return response()->json(['artist' => null]);
        }

        try {
            $payload = $geniusClient->artist((int) $artist->genius_id);

            if (! $payload) {
                return response()->json(['artist' => null]);
            }

            return response()->json([
                'artist' => [
                    'id' => (int) ($payload['id'] ?? 0),
                    'name' => GeniusNameMatcher::storageValue((string) ($payload['name'] ?? $artist->name)),
                    'description_preview' => $payload['description_preview'] ?? null,
                    'social_links' => array_filter((array) ($payload['social_links'] ?? [])),
                    'image_url' => $geniusClient->artistImageUrl($payload),
                    'url' => $payload['url'] ?? null,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Artist genius runtime fetch failed.', [
                'artist_id' => $artist->id,
                'genius_id' => $artist->genius_id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['artist' => null]);
        }
    }
}
