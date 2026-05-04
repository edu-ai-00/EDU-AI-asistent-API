<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStats extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_stats';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'level',
        'xp_points',
        'courses_count',
        'streak_days',
        'achievements_count',
        'last_streak_date',
        'daily_xp_date',
        'daily_xp_amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'xp_points' => 'integer',
            'courses_count' => 'integer',
            'streak_days' => 'integer',
            'achievements_count' => 'integer',
            'last_streak_date' => 'date',
            'daily_xp_date' => 'date',
            'daily_xp_amount' => 'integer',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the user that owns these stats.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helper Methods
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get or create stats for a user.
     */
    public static function getOrCreateForUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'level' => 1,
                'xp_points' => 0,
                'courses_count' => 0,
                'streak_days' => 0,
                'achievements_count' => 0,
            ]
        );
    }
}
