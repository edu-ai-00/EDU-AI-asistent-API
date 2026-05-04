<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'pin',
        'starts_with_quiz',
        'only_once',
        'logged_only',
        'quiz_evaluate',
        'only_quiz',
        'name',
        'description',
        'author',
        'emoji',
        'lesson_count',
        'estimated_minutes',
        'version',
        'status',
        'language',
        'data',
        'file_path',
        'file_size',
        'file_uploaded_at',
        'created_by',
        'vector_id',
    ];

    /**
     * The attributes that should be hidden in serialization.
     * The 'data' field is excluded from listings - use download endpoint.
     *
     * @var list<string>
     */
    protected $hidden = [
        'data',
    ];

    protected $appends = [
        'code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'version' => 'integer',
            'lesson_count' => 'integer',
            'estimated_minutes' => 'integer',
            'file_size' => 'integer',
            'file_uploaded_at' => 'datetime',
            'starts_with_quiz' => 'boolean',
            'only_once' => 'boolean',
            'logged_only' => 'boolean',
            'quiz_evaluate' => 'boolean',
            'only_quiz' => 'boolean',
        ];
    }

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-increment version on update
        static::updating(function (Course $course) {
            if ($course->isDirty() && !$course->isDirty('version')) {
                $course->version = $course->version + 1;
            }
        });
    }

    /**
     * Scope for published courses only.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope for filtering by language.
     */
    public function scopeLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Scope for courses updated since a given timestamp.
     */
    public function scopeUpdatedSince($query, string $timestamp)
    {
        return $query->where('updated_at', '>=', $timestamp);
    }

    /**
     * Check if this course has been uploaded to R2.
     */
    /**
     * Legacy alias: return pin as code.
     */
    public function getCodeAttribute(): ?string
    {
        return $this->pin;
    }

    public function hasR2File(): bool
    {
        return !empty($this->file_path);
    }

    /**
     * Get the R2 file path for a specific version.
     */
    public static function generateFilePath(string $courseId, int $version): string
    {
        return "courses/{$courseId}/v{$version}.json";
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_courses')
            ->withTimestamps();
    }

    public function userCourses(): HasMany
    {
        return $this->hasMany(UserCourse::class);
    }

    public function skillConfig(): HasOne
    {
        return $this->hasOne(CourseSkillConfig::class);
    }

    public function skillVector(): BelongsTo
    {
        return $this->belongsTo(SkillVector::class, 'vector_id');
    }
}
