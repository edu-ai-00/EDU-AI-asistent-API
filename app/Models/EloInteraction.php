<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EloInteraction extends Model
{
    protected $fillable = [
        'user_id',
        'block_id',
        'course_id',
        'source',
        'score',
        'profil_elo_snapshot',
        'elo_vector_snapshot',
        'updated_indices',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'profil_elo_snapshot' => 'array',
            'elo_vector_snapshot' => 'array',
            'updated_indices' => 'array',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the user that owns this interaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
