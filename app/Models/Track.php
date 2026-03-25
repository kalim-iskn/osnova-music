<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Track extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'artist_id',
        'album_id',
        'title',
        'duration_seconds',
        'audio_url',
        'cover_image_url',
        'track_number',
        'is_downloaded',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'track_number' => 'integer',
            'is_downloaded' => 'boolean',
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

    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'track_user_likes')->withTimestamps();
    }

    public function getDurationHumanAttribute(): string
    {
        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
