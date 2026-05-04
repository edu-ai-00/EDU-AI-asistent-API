<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillVector extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'dimension_count',
        'version',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'dimension_count' => 'integer',
            'version' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function dimensions(): HasMany
    {
        return $this->hasMany(VectorDimension::class, 'vector_id')->orderBy('dimension_index');
    }

    public function courseSkillConfigs(): HasMany
    {
        return $this->hasMany(CourseSkillConfig::class, 'vector_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'vector_id');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
