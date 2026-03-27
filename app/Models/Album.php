<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'artist_id',
        'title',
        'slug',
        'cover_image_url',
        'release_date',
        'genius_id',
    ];

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'genius_id' => 'integer',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'album_artist')->withTimestamps();
    }

    public function creditedArtists(): BelongsToMany
    {
        return $this->artists();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
