<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use Inertia\Inertia;
use Inertia\Response;

class ArtistController extends Controller
{
    public function show(Artist $artist): Response
    {
        $artist->loadCount('tracks');

        $albums = $artist->albums()
            ->with(['artist'])
            ->withCount('tracks')
            ->latest('id')
            ->get();

        $tracks = $artist->tracks()
            ->with(['artist', 'album'])
            ->latest('id')
            ->get();

        return Inertia::render('Artists/Show', [
            'artist' => ArtistResource::make($artist)->resolve(),
            'albums' => AlbumResource::collection($albums)->resolve(),
            'tracks' => TrackResource::collection($tracks)->resolve(),
        ]);
    }
}
