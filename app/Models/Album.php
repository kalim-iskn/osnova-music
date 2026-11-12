<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'release_date_components',
        'genius_id',
    ];

    protected function casts(): array
    {
        return [
            'release_date_components' => 'array',
            'genius_id' => 'integer',
        ];
    }

    /** A compatibility view of a complete date; components remain the stored source. */
    public function getReleaseDateAttribute(): ?Carbon
    {
        $parts = $this->release_date_components;
        $year = (int) ($parts['year'] ?? 0);
        $month = (int) ($parts['month'] ?? 0);
        $day = (int) ($parts['day'] ?? 0);

        return $year > 0 && checkdate($month, $day, $year)
            ? Carbon::create($year, $month, $day, 0, 0, 0)
            : null;
    }

    public function setReleaseDateAttribute($value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);
        $this->release_date_components = ['year' => $date->year, 'month' => $date->month, 'day' => $date->day];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'album_track')
            ->withPivot('is_primary', 'track_number')
            ->withTimestamps()
            ->orderByPivotDesc('is_primary')
            ->orderByRaw('album_track.track_number IS NULL, album_track.track_number ASC')
            ->orderBy('tracks.id');
    }

    public function primaryTracks(): HasMany
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
