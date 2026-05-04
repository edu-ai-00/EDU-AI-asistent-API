<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VectorDimension extends Model
{
    use HasUuids;

    protected $fillable = [
        'vector_id',
        'dimension_index',
        'code',
        'name',
        'domain_code',
        'domain_name',
        'construct_name',
        'description',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'dimension_index' => 'integer',
            'tags' => 'array',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function vector(): BelongsTo
    {
        return $this->belongsTo(SkillVector::class, 'vector_id');
    }
}
