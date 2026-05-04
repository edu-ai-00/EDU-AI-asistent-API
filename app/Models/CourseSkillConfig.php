<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseSkillConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'course_id',
        'vector_id',
        'formula_json',
        'display_scale_min',
        'display_scale_max',
        'confidence_c',
        'min_count',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'formula_json' => 'array',
            'display_scale_min' => 'float',
            'display_scale_max' => 'float',
            'confidence_c' => 'float',
            'min_count' => 'integer',
            'version' => 'integer',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function vector(): BelongsTo
    {
        return $this->belongsTo(SkillVector::class, 'vector_id');
    }
}
