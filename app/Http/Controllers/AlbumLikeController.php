<?php

namespace App\Http\Controllers;

use App\Models\Album;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumLikeController extends Controller
{
    public function store(Request $request, Album $album): JsonResponse
    {
        $request->user()->likedAlbums()->syncWithoutDetaching([$album->id]);

        return $this->response($request, true);
    }

    public function destroy(Request $request, Album $album): JsonResponse
    {
        $request->user()->likedAlbums()->detach($album->id);

        return $this->response($request, false);
    }

    private function response(Request $request, bool $liked): JsonResponse
    {
        return response()->json([
            'liked' => $liked,
            'liked_album_ids' => $request->user()->likedAlbums()->pluck('albums.id')->all(),
        ]);
    }
}
