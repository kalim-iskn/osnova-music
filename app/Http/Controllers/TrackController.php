<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class TrackController extends Controller
{
    public function show(Track $track): Response
    {
        $track->load([
            'artist',
            'artists',
            'album.artist',
        ]);

        $relatedTracks = Track::query()
            ->whereKeyNot($track->id)
            ->where(function (Builder $query) use ($track): void {
                if ($track->album_id) {
                    $query->where('album_id', $track->album_id);

                    return;
                }

                $artistIds = $track->artists->pluck('id')->all();

                if ($artistIds !== []) {
                    $query->whereHas('artists', fn (Builder $artistQuery) => $artistQuery->whereIn('artists.id', $artistIds));
                }
            })
            ->with(['artist', 'artists', 'album'])
            ->popular()
            ->limit(12)
            ->get();

        return Inertia::render('Tracks/Show', [
            'track' => TrackResource::make($track)->resolve(),
            'relatedTracks' => TrackResource::collection($relatedTracks)->resolve(),
        ]);
    }
}
