<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCourse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'progress_percent',
        'status',
        'completed_lessons',
        'total_lessons',
        'current_lesson_index',
        'time_spent_seconds',
        'downloaded_version',
        'downloaded_at',
        'progress_data',
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'completed_lessons' => 'integer',
            'total_lessons' => 'integer',
            'current_lesson_index' => 'integer',
            'time_spent_seconds' => 'integer',
            'downloaded_version' => 'integer',
            'downloaded_at' => 'datetime',
            'progress_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the user that owns this course enrollment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course this enrollment belongs to.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Scope to filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by updated_since timestamp.
     */
    public function scopeUpdatedSince($query, string $timestamp)
    {
        return $query->where('updated_at', '>=', $timestamp);
    }

    /**
     * Scope to include only completed courses.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to include only in-progress courses.
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope to include only downloaded (not started) courses.
     */
    public function scopeDownloaded($query)
    {
        return $query->where('status', 'downloaded');
    }
}
