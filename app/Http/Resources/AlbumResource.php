<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Album */
class AlbumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'cover_image_url' => $this->cover_image_url,
            'release_date' => $this->release_date?->toDateString(),
            'artist' => $this->whenLoaded('artist', fn () => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'slug' => $this->artist->slug,
            ]),
            'tracks_count' => $this->whenCounted('tracks'),
        ];
    }
}
