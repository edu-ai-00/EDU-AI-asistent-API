<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenAnswerEvaluation extends Model
{
    protected $fillable = [
        'user_id',
        'client_uuid',
        'course_id',
        'lesson_id',
        'block_id',
        'step_id',
        'answer_text',
        'is_correct',
        'ai_score',
        'ai_feedback',
        'evaluated_by_ai',
        'ai_model',
        'teacher_override_score',
        'answered_at',
        // Formative feedback + audit trail (BR-P745Q8)
        'feedback_parts',
        'criteria_scores',
        'grading_basis',
        'attempt_status',
        'attempt_number',
        'photo_path',
        'prompt_version',
    ];

    /** An evaluation that produced feedback the student was shown. */
    public const STATUS_EVALUATED = 'evaluated';

    /**
     * AI was unreachable or returned something unusable. The student saw a
     * retry, not a verdict — this row exists so the failure is auditable and
     * so a later retry does not overwrite it.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Legacy. A background second opinion on an answer that was graded by exact
     * match and never shown to the student (BR-EDB2MB). No longer written:
     * `ai_evaluation` off now means the AI does not run at all (BR-WBY5AF), and
     * the store endpoint rejects the status. Kept so the rows already recorded
     * stay out of the student-facing history.
     */
    public const STATUS_SHADOW = 'shadow';

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'evaluated_by_ai' => 'boolean',
            'ai_score' => 'float',
            'teacher_override_score' => 'float',
            'answered_at' => 'datetime',
            'feedback_parts' => 'array',
            'criteria_scores' => 'array',
            'grading_basis' => 'array',
            'attempt_number' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
