<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use Inertia\Inertia;
use Inertia\Response;

class AlbumController extends Controller
{
    public function show(Album $album): Response
    {
        $album->load(['artist']);
        $album->loadCount('tracks');

        $tracks = $album->tracks()
            ->with(['artist', 'album'])
            ->orderByRaw('track_number IS NULL, track_number ASC')
            ->orderBy('id')
            ->get();

        return Inertia::render('Albums/Show', [
            'album' => AlbumResource::make($album)->resolve(),
            'tracks' => TrackResource::collection($tracks)->resolve(),
        ]);
    }
}
