<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEloProfile extends Model
{
    protected $fillable = [
        'user_id',
        'profil_elo',
        'profil_pocet',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profil_elo' => 'array',
            'profil_pocet' => 'array',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the user that owns this ELO profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Scope to filter by user ID.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
