<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFsrsProfile extends Model
{
    protected $fillable = [
        'user_id', 'desired_retention', 'maximum_interval', 'enable_fuzz',
        'enable_short_term', 'learning_steps', 'relearning_steps',
        'fsrs_weights', 'profile_version', 'daily_new_limit',
        'daily_review_limit', 'session_expiration_sec',
    ];

    protected $casts = [
        'desired_retention' => 'double',
        'enable_fuzz' => 'boolean',
        'enable_short_term' => 'boolean',
        'learning_steps' => 'array',
        'relearning_steps' => 'array',
        'fsrs_weights' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
