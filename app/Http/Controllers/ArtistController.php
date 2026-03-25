<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use Illuminate\Pagination\LengthAwarePaginator;
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
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Artists/Show', [
            'artist' => ArtistResource::make($artist)->resolve(),
            'albums' => AlbumResource::collection($albums)->resolve(),
            'tracks' => $this->resolveTracksPaginator($tracks),
        ]);
    }

    private function resolveTracksPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => TrackResource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}
