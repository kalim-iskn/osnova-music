<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Services\TrackSearchService;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(SearchRequest $request, TrackSearchService $searchService): Response
    {
        $term = trim((string) ($request->validated()['q'] ?? ''));
        $tracks = $searchService->searchTracksPaginated($term, 20);

        return Inertia::render('Search/Index', [
            'term' => $term,
            'tracks' => $this->resolveTracksPaginator($tracks),
            'artists' => ArtistResource::collection($searchService->searchArtists($term))->resolve(),
            'albums' => AlbumResource::collection($searchService->searchAlbums($term))->resolve(),
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
