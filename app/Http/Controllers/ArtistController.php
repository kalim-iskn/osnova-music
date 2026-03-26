<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class ArtistController extends Controller
{
    public function show(Artist $artist): Response
    {
        $creditedTracksQuery = Track::query()
            ->whereHas('artists', fn ($query) => $query->whereKey($artist->id));

        $artist->setAttribute('tracks_count', (clone $creditedTracksQuery)->count());
        $artist->setAttribute('plays_count', (int) (clone $creditedTracksQuery)->sum('plays_count'));

        $albums = $artist->albums()
            ->with(['artist'])
            ->withCount('tracks')
            ->latest('release_date')
            ->latest('id')
            ->get();

        $tracks = $creditedTracksQuery
            ->with(['artist', 'artists', 'album'])
            ->latest('release_year')
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
