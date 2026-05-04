<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillEventLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'student_id',
        'course_id',
        'session_id',
        'block_id',
        'dimension_indices',
        'delta_values',
        'score',
        'ts_bubble_open',
        'ts_answer_click',
        'ts_answer_submit',
        'is_correct',
        'attempt_count',
        'help_used',
        'time_on_task_ms',
        'extra_events_json',
    ];

    protected function casts(): array
    {
        return [
            'dimension_indices' => 'array',
            'delta_values' => 'array',
            'score' => 'float',
            'ts_bubble_open' => 'integer',
            'ts_answer_click' => 'integer',
            'ts_answer_submit' => 'integer',
            'is_correct' => 'boolean',
            'attempt_count' => 'integer',
            'help_used' => 'boolean',
            'time_on_task_ms' => 'integer',
            'extra_events_json' => 'array',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
