<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParsingTaskTrack extends Model
{
    protected $fillable = [
        'parsing_task_id',
        'source_key',
        'track_title',
        'matched',
        'completed',
    ];

    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'completed' => 'boolean',
        ];
    }
}
