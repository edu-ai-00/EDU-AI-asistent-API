<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeReviewLog extends Model
{
    protected $table = 'practice_review_logs';

    protected $fillable = [
        'card_id', 'user_id', 'rating', 'shown_at', 'reviewed_at',
        'response_time_sec', 'repetition_number', 'stability_after',
        'difficulty_after', 'next_due_date', 'interval_days', 'user_feedback',
    ];

    protected $casts = [
        'shown_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'next_due_date' => 'datetime',
        'stability_after' => 'double',
        'difficulty_after' => 'double',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(PracticeCard::class, 'card_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
