<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrackResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $tracks = $request->user()
            ->likedTracks()
            ->with(['artist', 'album'])
            ->get();

        return Inertia::render('Library/Tracks', [
            'tracks' => TrackResource::collection($tracks)->resolve(),
        ]);
    }
}
