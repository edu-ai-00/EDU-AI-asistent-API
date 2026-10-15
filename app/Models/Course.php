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
        'translation_key',
        'structure_hash',
        'pin',
        'starts_with_quiz',
        'only_once',
        'logged_only',
        'quiz_evaluate',
        'only_quiz',
        'open_all_lessons',
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
        'header_image',
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
            'open_all_lessons' => 'boolean',
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
     * The other language variants of this course (BR-AYACEK).
     *
     * Variants share a `translation_key` but keep separate `course_id`s, so
     * this is a self-join rather than a relation on the primary key.
     */
    public function translations()
    {
        return static::query()
            ->where('translation_key', $this->translation_key ?: $this->course_id)
            ->where('id', '!=', $this->id);
    }

    /**
     * Does this variant's structure still agree with the given sibling?
     * Unknown (null) fingerprints are treated as agreeing — a course that has
     * never been fingerprinted must not block anyone.
     */
    public function structureAgreesWith(self $other): bool
    {
        if ($this->structure_hash === null || $other->structure_hash === null) {
            return true;
        }

        return $this->structure_hash === $other->structure_hash;
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

    /**
     * Course cover for listings (BR-YKYXC6).
     *
     * The cover lives in the course JSON (`data.header_image`), and `data` is
     * hidden from listing payloads — without this the app could only show a
     * cover for courses it had already downloaded. Null when the column was
     * not selected (checkUpdates) or no cover is set.
     */
    public function getHeaderImageAttribute(): ?array
    {
        $image = $this->data['header_image'] ?? null;

        if (!is_array($image) || empty($image['url'])) {
            return null;
        }

        return [
            'url' => $image['url'],
            'alt' => $image['alt'] ?? null,
        ];
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
