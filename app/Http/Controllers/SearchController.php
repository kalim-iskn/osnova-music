<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Services\TrackSearchService;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(SearchRequest $request, TrackSearchService $searchService): Response
    {
        $term = trim((string) ($request->validated()['q'] ?? ''));

        return Inertia::render('Search/Index', [
            'term' => $term,
            'tracks' => TrackResource::collection($searchService->searchTracks($term))->resolve(),
            'artists' => ArtistResource::collection($searchService->searchArtists($term))->resolve(),
            'albums' => AlbumResource::collection($searchService->searchAlbums($term))->resolve(),
        ]);
    }
}
