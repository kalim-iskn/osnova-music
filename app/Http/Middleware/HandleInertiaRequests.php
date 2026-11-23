<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'liked_track_ids' => $request->user()->likedTracks()->pluck('tracks.id')->all(),
                        'liked_album_ids' => $request->user()->likedAlbums()->pluck('albums.id')->all(),
                    ]
                    : null,
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
            ],
        ]);
    }
}
