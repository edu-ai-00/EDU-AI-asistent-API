<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EloInteraction extends Model
{
    protected $fillable = [
        'user_id',
        'client_uuid',
        'block_id',
        'course_id',
        'source',
        'score',
        'profil_elo_before',
        'profil_elo_snapshot',
        'elo_vector_used',
        'elo_vector_snapshot',
        'task_applied',
        'task_skip_reason',
        'updated_indices',
        'opened_at',
        'confirmed_at',
        'duration_ms',
        'elo_config_version',
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
            'profil_elo_before' => 'array',
            'profil_elo_snapshot' => 'array',
            'elo_vector_used' => 'array',
            'elo_vector_snapshot' => 'array',
            'task_applied' => 'boolean',
            'updated_indices' => 'array',
            'opened_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'duration_ms' => 'integer',
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
