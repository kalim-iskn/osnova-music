<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $featuredTracks = Track::query()
            ->with(['artist', 'album'])
            ->latest('id')
            ->limit(12)
            ->get();

        $spotlightArtists = Artist::query()
            ->withCount('tracks')
            ->withSum('tracks as plays_count', 'plays_count')
            ->latest('id')
            ->limit(6)
            ->get();

        $freshAlbums = Album::query()
            ->with(['artist'])
            ->withCount('tracks')
            ->latest('id')
            ->limit(8)
            ->get();

        return Inertia::render('Home', [
            'featuredTracks' => TrackResource::collection($featuredTracks)->resolve(),
            'spotlightArtists' => ArtistResource::collection($spotlightArtists)->resolve(),
            'freshAlbums' => AlbumResource::collection($freshAlbums)->resolve(),
        ]);
    }
}
