<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParsingRun extends Model
{
    protected $fillable = [
        'kind',
        'source_url',
        'status',
        'stage',
        'options',
        'total_tasks',
        'completed_tasks',
        'failed_tasks',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ParsingTask::class);
    }

    public function refreshFromTasks(): void
    {
        $counts = $this->tasks()
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running")
            ->first();

        $total = (int) ($counts?->total ?? 0);
        $completed = (int) ($counts?->completed ?? 0);
        $failed = (int) ($counts?->failed ?? 0);
        $cancelled = (int) ($counts?->cancelled ?? 0);
        $running = (int) ($counts?->running ?? 0);

        if ($total === 0) {
            return;
        }

        $terminal = $completed + $failed + $cancelled === $total;
        $status = $terminal
            ? ($completed > 0 ? 'completed' : ($failed > 0 ? 'failed' : 'cancelled'))
            : ($running > 0 ? 'running' : 'queued');

        $this->forceFill([
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'failed_tasks' => $failed,
            'status' => $status,
            'stage' => $terminal ? ($status === 'cancelled' ? 'cancelled' : 'finished') : ($running > 0 ? 'processing_artists' : 'queued'),
            'finished_at' => $terminal ? ($this->finished_at ?? now()) : null,
        ])->save();
    }
}
