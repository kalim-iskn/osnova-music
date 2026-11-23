<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Artist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $artistId = $this->artistId($request);
        $tracks = $this->trackQuery($request, $artistId)
            ->with(['artist', 'artists', 'album', 'albums'])
            ->orderByDesc('tracks.plays_count')
            ->orderByDesc('tracks.genius_pageviews')
            ->orderByDesc('tracks.id')
            ->paginate(20)
            ->withQueryString();

        $releases = $request->user()->likedAlbums()
            ->when($artistId, fn ($query) => $query->where(fn ($query) => $query
                ->where('albums.artist_id', $artistId)
                ->orWhereHas('artists', fn ($artists) => $artists->whereKey($artistId))))
            ->with(['artist', 'artists'])->withCount('tracks')
            ->paginate(20, ['albums.*'], 'releases_page')->withQueryString();

        $likedIds = $request->user()->likedTracks()->reorder()->select('tracks.id');
        $artists = Artist::query()->where(fn ($query) => $query
            ->whereHas('tracks', fn ($tracks) => $tracks->whereIn('tracks.id', clone $likedIds))
            ->orWhereHas('creditedTracks', fn ($tracks) => $tracks->whereIn('tracks.id', clone $likedIds))
            ->orWhereHas('albums', fn ($albums) => $albums->whereIn('albums.id', $request->user()->likedAlbums()->reorder()->select('albums.id')))
            ->orWhereHas('creditedAlbums', fn ($albums) => $albums->whereIn('albums.id', $request->user()->likedAlbums()->reorder()->select('albums.id'))))
            ->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Library/Tracks', [
            'tracks' => $this->resolveTracksPaginator($tracks),
            'releases' => [
                'data' => AlbumResource::collection($releases->getCollection())->resolve(),
                'meta' => ['current_page' => $releases->currentPage(), 'last_page' => $releases->lastPage(), 'total' => $releases->total()],
                'links' => ['prev' => $releases->previousPageUrl(), 'next' => $releases->nextPageUrl()],
            ],
            'artists' => $artists,
            'artistId' => $artistId,
            'likedTracksTotal' => $request->user()->likedTracks()->count(),
            'likedReleasesTotal' => $request->user()->likedAlbums()->count(),
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $tracks = $this->trackQuery($request, $this->artistId($request))
            ->with(['artist', 'artists', 'album', 'albums'])
            ->orderByDesc('tracks.plays_count')->orderByDesc('tracks.genius_pageviews')->orderByDesc('tracks.id')->get();

        return response()->json(['tracks' => TrackResource::collection($tracks)->resolve()]);
    }

    private function artistId(Request $request): ?int
    {
        return $request->validate(['artist' => ['nullable', 'integer', 'exists:artists,id']])['artist'] ?? null;
    }

    private function trackQuery(Request $request, ?int $artistId)
    {
        return $request->user()->likedTracks()->when($artistId, fn ($query) => $query
            ->where(fn ($query) => $query->where('tracks.artist_id', $artistId)
                ->orWhereHas('artists', fn ($artists) => $artists->whereKey($artistId))));
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
