<?php

namespace App\Http\Resources;

use App\Support\ArtworkUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Album */
class AlbumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $coverImageUrl = ArtworkUrl::usable($this->cover_image_url)
            ?: asset('album-placeholder.svg');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'genius_id' => $this->genius_id,
            'release_type' => \App\Support\ReleaseType::normalize($this->resource->getAttributes()['genius_album_type'] ?? null)
                ?? \App\Support\ReleaseType::normalize($this->resource->getAttributes()['muzofond_album_type'] ?? null),
            'cover_image_url' => $coverImageUrl,
            'release_date' => $this->release_date?->toDateString(),
            'release_date_components' => $this->release_date_components,
            'artist' => $this->whenLoaded('artist', fn () => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'slug' => $this->artist->slug,
            ]),
            'artists' => $this->whenLoaded('artists', fn () => $this->artists
                ->map(fn ($artist) => [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'slug' => $artist->slug,
                    'image_url' => ArtworkUrl::usable($artist->image_url) ?: asset('artist-placeholder.svg'),
                    'genius_id' => $artist->genius_id,
                ])
                ->values()
                ->all()),
            'tracks_count' => (int) ($this->tracks_count ?? 0),
        ];
    }
}
