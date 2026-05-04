<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTheme extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'theme_config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'theme_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
