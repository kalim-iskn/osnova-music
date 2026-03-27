<?php

namespace App\Models;

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
        return $this->belongsToMany(Track::class, 'artist_track')->withTimestamps();
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
