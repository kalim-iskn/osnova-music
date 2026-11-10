<?php

namespace App\Http\Resources;

use App\Models\Artist;
use App\Models\Album;
use App\Support\ArtworkUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin \App\Models\Track */
class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $displayAlbum = $this->displayAlbum();
        $coverImageUrl = $displayAlbum
            ? (ArtworkUrl::usable($displayAlbum->cover_image_url) ?: asset('album-placeholder.svg'))
            : (ArtworkUrl::usable($this->cover_image_url) ?: asset('track-placeholder.svg'));

        $resolvedArtists = $this->relationLoaded('artists') && $this->artists->isNotEmpty()
            ? $this->artists
            : collect($this->artist ? [$this->artist] : []);

        $resolvedArtists = $resolvedArtists
            ->sortBy(function (Artist $artist): array {
                $pivot = $artist->getRelations()['pivot'] ?? null;

                return [
                    ($pivot?->credit_role ?? 'primary') === 'featured' ? 1 : 0,
                    (int) ($pivot?->credit_position ?? 0),
                ];
            })
            ->values();

        /** @var Artist|null $primaryArtist */
        $primaryArtist = $resolvedArtists->first() ?: $this->artist;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'show_url' => route('tracks.show', ['track' => $this->id]),
            'duration_seconds' => (int) $this->duration_seconds,
            'duration_human' => $this->duration_human,
            'audio_url' => $this->audio_url,
            'playback_url' => route('tracks.stream', $this->resource),
            'cover_image_url' => $coverImageUrl,
            'track_number' => $this->resource->pivot?->track_number ?? $this->track_number,
            'is_downloaded' => (bool) $this->is_downloaded,
            'plays_count' => (int) ($this->plays_count ?? 0),
            'genius_pageviews' => (int) ($this->genius_pageviews ?? 0),
            'release_year' => $this->release_year,
            'genres' => array_values(array_filter((array) ($this->genres ?? []))),
            'language' => $this->language,
            'description_preview' => $this->description_preview,
            'genius_id' => $this->genius_id,
            'genius_url' => $this->genius_url,
            'audio_version_ambiguous' => (bool) $this->audio_version_ambiguous,
            'artist' => $primaryArtist
                ? [
                    'id' => $primaryArtist->id,
                    'name' => $primaryArtist->name,
                    'slug' => $primaryArtist->slug,
                    'image_url' => ArtworkUrl::usable($primaryArtist->image_url) ?: asset('artist-placeholder.svg'),
                    'genius_id' => $primaryArtist->genius_id,
                ]
                : null,
            'artists' => $resolvedArtists
                ->map(function (Artist $artist): array {
                    $pivot = $artist->getRelations()['pivot'] ?? null;

                    return [
                        'id' => $artist->id,
                        'name' => $artist->name,
                        'slug' => $artist->slug,
                        'image_url' => ArtworkUrl::usable($artist->image_url) ?: asset('artist-placeholder.svg'),
                        'genius_id' => $artist->genius_id,
                        'credit_role' => $pivot?->credit_role ?? 'primary',
                        'credit_position' => $pivot?->credit_position !== null
                            ? (int) $pivot->credit_position
                            : null,
                    ];
                })
                ->values()
                ->all(),
            'album' => $displayAlbum
                ? [
                    'id' => $displayAlbum->id,
                    'title' => $displayAlbum->title,
                    'slug' => $displayAlbum->slug,
                    'cover_image_url' => ArtworkUrl::usable($displayAlbum->cover_image_url) ?: asset('album-placeholder.svg'),
                    'artists' => $displayAlbum->relationLoaded('artists')
                        ? $displayAlbum->artists->map(fn (Artist $artist) => [
                            'id' => $artist->id,
                            'name' => $artist->name,
                            'slug' => $artist->slug,
                            'image_url' => ArtworkUrl::usable($artist->image_url) ?: asset('artist-placeholder.svg'),
                        ])->values()->all()
                        : [],
                ]
                : null,
        ];
    }

    private function displayAlbum(): ?Album
    {
        if ($this->resource->relationLoaded('albums')) {
            // Album pages attach this virtual context only to their track rows.
            // Other endpoints load the same resource without it, and Eloquent's
            // strict missing-attribute mode must not treat the optional value as required.
            $attributes = $this->resource->getAttributes();
            $albumContextId = (int) ($attributes['_album_context_id'] ?? 0);

            if ($albumContextId > 0) {
                $contextAlbum = $this->albums->first(
                    fn (Album $album): bool => (int) $album->id === $albumContextId
                );

                if ($contextAlbum instanceof Album) {
                    return $contextAlbum;
                }
            } else {
                $primaryAlbum = $this->albums
                    ->sortByDesc(fn (Album $album): int => (int) ($album->pivot?->is_primary ?? false))
                    ->first();

                if ($primaryAlbum instanceof Album) {
                    return $primaryAlbum;
                }
            }

            // The pivot relation is authoritative when it was loaded. An empty
            // collection means the old tracks.album_id value is stale.
            return null;
        }

        $album = $this->album;

        if (! $album instanceof Album) {
            return null;
        }

        if ((int) ($this->resource->album_id ?? 0) === (int) $album->id) {
            return $album;
        }

        if (isset($this->resource->pivot?->album_id)
            && (int) $this->resource->pivot->album_id === (int) $album->id) {
            return $album;
        }

        return DB::table('album_track')
            ->where('track_id', $this->id)
            ->where('album_id', $album->id)
            ->exists()
                ? $album
                : null;
    }
}
