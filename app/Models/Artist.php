<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'image_url',
        'genius_id',
        'description_preview',
    ];

    protected function casts(): array
    {
        return [
            'genius_id' => 'integer',
        ];
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function creditedTracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'artist_track')
            ->withPivot('credit_role', 'credit_position')
            ->withTimestamps();
    }

    public function scopeWithCatalogTrackCount(Builder $query): void
    {
        $query->addSelect(['tracks_count' => Track::query()
            ->selectRaw('count(*)')
            ->where(fn (Builder $tracks) => $tracks
                ->whereColumn('tracks.artist_id', 'artists.id')
                ->orWhereExists(fn ($credits) => $credits->selectRaw('1')->from('artist_track')
                    ->whereColumn('artist_track.track_id', 'tracks.id')
                    ->whereColumn('artist_track.artist_id', 'artists.id')))]);
    }

    public function creditedAlbums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class, 'album_artist')->withTimestamps();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
