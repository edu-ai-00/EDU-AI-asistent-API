<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProgress extends Model
{
    protected $table = 'user_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'progress_percent',
        'is_completed',
        'last_position',
        'time_spent_seconds',
        'progress_data',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_data' => 'array',
            'is_completed' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
