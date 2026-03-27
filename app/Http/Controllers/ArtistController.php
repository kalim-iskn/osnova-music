<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

        $albums = $this->resolveArtistAlbums($artist);

        $tracks = $creditedTracksQuery
            ->with(['artist', 'artists', 'album', 'album.artists'])
            ->popular()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Artists/Show', [
            'artist' => ArtistResource::make($artist)->resolve(),
            'albums' => AlbumResource::collection($albums)->resolve(),
            'tracks' => $this->resolveTracksPaginator($tracks),
        ]);
    }

    /**
     * @return Collection<int, Album>
     */
    private function resolveArtistAlbums(Artist $artist): Collection
    {
        $relations = ['artist', 'artists'];

        $primaryAlbums = $artist->albums()
            ->with($relations)
            ->withCount('tracks')
            ->get();

        $creditedAlbums = $artist->creditedAlbums()
            ->with($relations)
            ->withCount('tracks')
            ->get();

        return $primaryAlbums
            ->concat($creditedAlbums)
            ->unique(fn (Album $album) => $album->id)
            ->sort(function (Album $left, Album $right): int {
                $leftRelease = $left->release_date ? strtotime((string) $left->release_date) : 0;
                $rightRelease = $right->release_date ? strtotime((string) $right->release_date) : 0;

                if ($leftRelease !== $rightRelease) {
                    return $rightRelease <=> $leftRelease;
                }

                return $right->id <=> $left->id;
            })
            ->values();
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
