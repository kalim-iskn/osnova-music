<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class AlbumController extends Controller
{
    public function show(Album $album): Response
    {
        $album->load(['artist', 'artists']);
        $album->loadCount('tracks');

        $tracks = $album->tracks()
            ->with(['artist', 'artists', 'album', 'album.artists'])
            ->orderByRaw('track_number IS NULL, track_number ASC')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Albums/Show', [
            'album' => AlbumResource::make($album)->resolve(),
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
