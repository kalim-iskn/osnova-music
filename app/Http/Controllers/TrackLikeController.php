<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackLikeController extends Controller
{
    public function store(Request $request, Track $track): JsonResponse
    {
        $request->user()->likedTracks()->syncWithoutDetaching([$track->id]);

        return response()->json([
            'liked' => true,
            'liked_track_ids' => $request->user()->likedTracks()->pluck('tracks.id')->all(),
        ]);
    }

    public function destroy(Request $request, Track $track): JsonResponse
    {
        $request->user()->likedTracks()->detach($track->id);

        return response()->json([
            'liked' => false,
            'liked_track_ids' => $request->user()->likedTracks()->pluck('tracks.id')->all(),
        ]);
    }
}
