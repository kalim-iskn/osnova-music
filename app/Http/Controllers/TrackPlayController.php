<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Http\JsonResponse;

class TrackPlayController extends Controller
{
    public function __invoke(Track $track): JsonResponse
    {
        abort_if(trim((string) $track->audio_url) === '', 404);
        $track->increment('plays_count');

        return response()->json([
            'plays_count' => (int) $track->fresh()->plays_count,
        ]);
    }
}
