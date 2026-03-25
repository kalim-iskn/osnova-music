<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Http\JsonResponse;

class TrackPlayController extends Controller
{
    public function __invoke(Track $track): JsonResponse
    {
        $track->increment('plays_count');

        return response()->json([
            'plays_count' => (int) $track->fresh()->plays_count,
        ]);
    }
}
