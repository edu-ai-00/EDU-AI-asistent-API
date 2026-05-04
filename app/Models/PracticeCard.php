<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeCard extends Model
{
    protected $fillable = [
        'user_id', 'course_id', 'lesson_id', 'block_id', 'source_type',
        'state', 'due_date', 'stability', 'difficulty', 'reps', 'lapses',
        'scheduled_days', 'elapsed_days', 'last_review', 'weight',
        'avg_time_sec', 'skip_condition', 'is_active',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'last_review' => 'datetime',
        'stability' => 'double',
        'difficulty' => 'double',
        'weight' => 'double',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewLogs(): HasMany
    {
        return $this->hasMany(PracticeReviewLog::class, 'card_id');
    }
}
