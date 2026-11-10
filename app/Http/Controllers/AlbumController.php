<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class AlbumController extends Controller
{
    public function show(Album $album): Response
    {
        $album->load(['artist', 'artists']);
        $album->loadCount('tracks');

        $trackOrder = DB::table('album_track')
            ->where('album_id', $album->id)
            ->selectRaw('COUNT(*) as total, COUNT(track_number) as numbered, SUM(CASE WHEN track_number > 0 THEN 1 ELSE 0 END) as positive_numbers')
            ->first();
        $showTrackNumbers = $trackOrder
            && (int) $trackOrder->total > 0
            && (int) $trackOrder->numbered === (int) $trackOrder->total
            && (int) $trackOrder->positive_numbers === (int) $trackOrder->total;

        $tracks = $album->tracks()
            ->with(['artist', 'artists', 'album', 'album.artists', 'albums', 'albums.artists'])
            ->reorder()
            ->orderByRaw('album_track.track_number IS NULL, album_track.track_number ASC')
            ->orderBy('tracks.id')
            ->paginate(20)
            ->withQueryString();

        $tracks->getCollection()->each(
            fn (Track $track) => $track->setAttribute('_album_context_id', (int) $album->id)
        );

        return Inertia::render('Albums/Show', [
            'album' => AlbumResource::make($album)->resolve(),
            'tracks' => $this->resolveTracksPaginator($tracks),
            'showTrackNumbers' => $showTrackNumbers,
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
