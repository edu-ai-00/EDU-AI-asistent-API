<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentFeedback extends Model
{
    protected $table = 'content_feedback';

    protected $fillable = [
        'user_id',
        'course_id',
        'block_id',
        'lesson_id',
        'type',
        'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
