<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Classroom extends Model
{
    use HasFactory;

    /**
     * Class code (BR-TT6TD7): four characters, A–Z and 0–9, stored upper
     * case. Four on purpose — a student code is six and a course PIN is six,
     * so the length alone tells the three apart wherever a code is typed.
     */
    public const JOIN_CODE_REGEX = '/^[A-Za-z0-9]{4}$/';

    protected $fillable = [
        'name',
        'type',
        'guardian_id',
        'school_id',
        'join_code',
    ];

    /**
     * Members of this class. Pivot since BR-3V2P22 — a student can be in
     * several classes, and an existing account can join by student code.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'user_id')
            ->withTimestamps();
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_teacher', 'classroom_id', 'teacher_id')
            ->withTimestamps();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'classroom_courses')
            ->withTimestamps();
    }

    public function hasTeacher(int $userId): bool
    {
        return $this->teachers()->where('users.id', $userId)->exists();
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isSelfService(): bool
    {
        return $this->type === 'self_service';
    }

    /** Canonical form of a typed class code: trimmed, upper case. */
    public static function normalizeJoinCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
