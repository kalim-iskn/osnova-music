<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParsingTask extends Model
{
    protected $fillable = [
        'parsing_run_id',
        'artist_url',
        'artist_slug',
        'artist_name',
        'status',
        'stage',
        'attempts',
        'page_limit',
        'tracks_total',
        'tracks_processed',
        'matched_tracks',
        'unmatched_tracks',
        'saved_tracks',
        'current_track',
        'muzofond_duration_ms',
        'genius_metrics',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'genius_metrics' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ParsingRun::class, 'parsing_run_id');
    }
}
