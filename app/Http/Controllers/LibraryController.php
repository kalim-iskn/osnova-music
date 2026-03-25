<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrackResource;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $tracks = $request->user()
            ->likedTracks()
            ->with(['artist', 'album'])
            ->latest('tracks.id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Library/Tracks', [
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
