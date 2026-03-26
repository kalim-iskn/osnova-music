<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class TrackSearchService
{
    public function searchTracks(?string $term, int $limit = 24): Collection
    {
        return $this->baseTrackQuery($term)
            ->latest('tracks.id')
            ->limit($limit)
            ->get();
    }

    public function searchTracksPaginated(?string $term, int $perPage = 20): LengthAwarePaginator
    {
        return $this->baseTrackQuery($term)
            ->latest('tracks.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function searchArtists(?string $term, int $limit = 12): Collection
    {
        $term = trim((string) $term);

        return Artist::query()
            ->withCount('tracks')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(fn ($subQuery) => $subQuery
                    ->where('name', 'ILIKE', "%{$term}%")
                    ->orWhere('slug', 'ILIKE', "%{$term}%"));
            })
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function searchAlbums(?string $term, int $limit = 12): Collection
    {
        $term = trim((string) $term);

        return Album::query()
            ->with(['artist'])
            ->withCount('tracks')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(fn ($subQuery) => $subQuery
                    ->where('title', 'ILIKE', "%{$term}%")
                    ->orWhere('slug', 'ILIKE', "%{$term}%"))
                    ->orWhereHas('artist', fn ($artistQuery) => $artistQuery
                        ->where('name', 'ILIKE', "%{$term}%"));
            })
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function baseTrackQuery(?string $term)
    {
        $term = trim((string) $term);

        return Track::query()
            ->with(['artist', 'album'])
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($subQuery) use ($term) {
                    $subQuery
                        ->where('tracks.title', 'ILIKE', "%{$term}%")
                        ->orWhereHas('artist', fn ($artistQuery) => $artistQuery
                            ->where('name', 'ILIKE', "%{$term}%")
                            ->orWhere('slug', 'ILIKE', "%{$term}%"))
                        ->orWhereHas('album', fn ($albumQuery) => $albumQuery
                            ->where('title', 'ILIKE', "%{$term}%")
                            ->orWhere('slug', 'ILIKE', "%{$term}%"));
                });
            });
    }
}
