<?php

namespace App\Http\Resources;

use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Track */
class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $coverImageUrl = $this->cover_image_url
            ?: $this->album?->cover_image_url
            ?: $this->artist?->image_url
            ?: asset('track-placeholder.svg');

        $resolvedArtists = $this->relationLoaded('artists') && $this->artists->isNotEmpty()
            ? $this->artists
            : collect($this->artist ? [$this->artist] : []);

        /** @var Artist|null $primaryArtist */
        $primaryArtist = $resolvedArtists->first() ?: $this->artist;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'show_url' => route('tracks.show', $this->resource),
            'duration_seconds' => (int) $this->duration_seconds,
            'duration_human' => $this->duration_human,
            'audio_url' => $this->audio_url,
            'playback_url' => route('tracks.stream', $this->resource),
            'cover_image_url' => $coverImageUrl,
            'track_number' => $this->track_number,
            'is_downloaded' => (bool) $this->is_downloaded,
            'plays_count' => (int) ($this->plays_count ?? 0),
            'release_year' => $this->release_year,
            'genres' => array_values(array_filter((array) ($this->genres ?? []))),
            'language' => $this->language,
            'description_preview' => $this->description_preview,
            'genius_id' => $this->genius_id,
            'genius_url' => $this->genius_url,
            'artist' => $primaryArtist
                ? [
                    'id' => $primaryArtist->id,
                    'name' => $primaryArtist->name,
                    'slug' => $primaryArtist->slug,
                ]
                : null,
            'artists' => $resolvedArtists
                ->map(fn (Artist $artist) => [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'slug' => $artist->slug,
                ])
                ->values()
                ->all(),
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
