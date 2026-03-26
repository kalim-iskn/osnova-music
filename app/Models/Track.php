<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Track extends Model
{
    protected $fillable = [
        'artist_id',
        'album_id',
        'title',
        'duration_seconds',
        'audio_url',
        'original_link',
        'audio_hash',
        'release_year',
        'genres',
        'language',
        'description_preview',
        'genius_url',
        'cover_image_url',
        'track_number',
        'is_downloaded',
        'plays_count',
        'genius_id',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'release_year' => 'integer',
            'genres' => 'array',
            'track_number' => 'integer',
            'is_downloaded' => 'boolean',
            'plays_count' => 'integer',
            'genius_id' => 'integer',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_track')->withTimestamps();
    }

    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'track_user_likes')->withTimestamps();
    }

    public function getDurationHumanAttribute(): string
    {
        $minutes = intdiv((int) $this->duration_seconds, 60);
        $seconds = (int) $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
