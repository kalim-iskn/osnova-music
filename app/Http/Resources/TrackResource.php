<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Track */
class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'duration_seconds' => $this->duration_seconds,
            'duration_human' => $this->duration_human,
            'audio_url' => $this->audio_url,
            'cover_image_url' => $this->cover_image_url,
            'track_number' => $this->track_number,
            'is_downloaded' => (bool) $this->is_downloaded,
            'artist' => $this->whenLoaded('artist', fn () => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'slug' => $this->artist->slug,
            ]),
            'album' => $this->album
                ? [
                    'id' => $this->album->id,
                    'title' => $this->album->title,
                    'slug' => $this->album->slug,
                ]
                : null,
        ];
    }
}
