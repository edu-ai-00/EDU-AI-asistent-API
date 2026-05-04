<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'login_code',
        'password',
        'email_verified_at',
        'avatar_index',
        'selected_subjects',
        'is_guest',
        'device_id',
        'classroom_id',
        'merged_into_user_id',
        'merged_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'selected_subjects' => 'array',
            'is_guest' => 'boolean',
            'merged_at' => 'datetime',
            'merged_into_user_id' => 'integer',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function courses(): HasMany
    {
        return $this->hasMany(UserCourse::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(UserStats::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(UserTheme::class);
    }

    /**
     * The target user this account was merged into, if any.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_user_id');
    }

    /**
     * True if this profile has been soft-merged into another account.
     * Login flows must reject merged users with HTTP 410.
     */
    public function isMerged(): bool
    {
        return $this->merged_into_user_id !== null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Role helpers
    // ═══════════════════════════════════════════════════════════════════════════

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isAdminOrTeacher(): bool
    {
        return in_array($this->role, ['admin', 'teacher']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Ownership relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function teachingClassrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_teacher', 'teacher_id', 'classroom_id')
            ->withTimestamps();
    }

    public function createdCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'created_by');
    }
}
