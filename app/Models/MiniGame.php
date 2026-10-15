<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class MiniGame extends Model
{
    use HasFactory;

    /**
     * Lock reasons returned to the client. The client maps these to copy;
     * it never recomputes the decision itself.
     */
    public const LOCK_LEVEL = 'level';

    public const LOCK_ATTEMPTS = 'attempts';

    public const LOCK_COOLDOWN = 'cooldown';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'title',
        'perex',
        'description',
        'image_url',
        'sensor',
        'duration_minutes',
        'min_level',
        'daily_attempts',
        'cooldown_hours',
        'setup',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'setup' => 'array',
            'is_active' => 'boolean',
            'duration_minutes' => 'integer',
            'min_level' => 'integer',
            'daily_attempts' => 'integer',
            'cooldown_hours' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Scope to games that should appear in the client listing.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the recorded plays of this game.
     */
    public function plays(): HasMany
    {
        return $this->hasMany(MiniGamePlay::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Entitlement
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Decide whether a user may play this game right now.
     *
     * The three gates are checked in the order the UI shows them: a game the
     * user has not unlocked yet never reports an attempt count, and a game
     * with attempts left never reports a cooldown.
     *
     * @param  Collection<int, MiniGamePlay>|null  $plays  This user's plays of
     *                                                     this game. Passed in
     *                                                     by the listing so it
     *                                                     can load every game's
     *                                                     plays in one query.
     * @return array{is_playable: bool, lock_reason: string|null, attempts_left: int|null, unlocks_at: CarbonInterface|null}
     */
    public function entitlementFor(int $userLevel, ?Collection $plays = null): array
    {
        $plays ??= $this->plays()->get();

        if ($userLevel < $this->min_level) {
            return [
                'is_playable' => false,
                'lock_reason' => self::LOCK_LEVEL,
                'attempts_left' => null,
                'unlocks_at' => null,
            ];
        }

        $playedToday = $plays->filter(
            fn (MiniGamePlay $play) => $play->played_at->isSameDay(now())
        )->count();

        $attemptsLeft = $this->daily_attempts === null
            ? null
            : max(0, $this->daily_attempts - $playedToday);

        if ($attemptsLeft === 0) {
            return [
                'is_playable' => false,
                'lock_reason' => self::LOCK_ATTEMPTS,
                'attempts_left' => 0,
                // The quota is per calendar day, so it refunds at midnight.
                'unlocks_at' => now()->addDay()->startOfDay(),
            ];
        }

        $lastPlayedAt = $plays->max('played_at');

        if ($this->cooldown_hours && $lastPlayedAt) {
            $readyAt = $lastPlayedAt->copy()->addHours($this->cooldown_hours);

            if ($readyAt->isFuture()) {
                return [
                    'is_playable' => false,
                    'lock_reason' => self::LOCK_COOLDOWN,
                    'attempts_left' => $attemptsLeft,
                    'unlocks_at' => $readyAt,
                ];
            }
        }

        return [
            'is_playable' => true,
            'lock_reason' => null,
            'attempts_left' => $attemptsLeft,
            'unlocks_at' => null,
        ];
    }

    /**
     * Serialise the game plus a user's entitlement into the client payload.
     *
     * @param  Collection<int, MiniGamePlay>|null  $plays
     * @return array<string, mixed>
     */
    public function toClientArray(int $userLevel, ?Collection $plays = null): array
    {
        $entitlement = $this->entitlementFor($userLevel, $plays);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'perex' => $this->perex,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'sensor' => $this->sensor,
            'duration_minutes' => $this->duration_minutes,
            'min_level' => $this->min_level,
            'daily_attempts' => $this->daily_attempts,
            'cooldown_hours' => $this->cooldown_hours,
            'setup' => $this->setup,
            'is_playable' => $entitlement['is_playable'],
            'lock_reason' => $entitlement['lock_reason'],
            'attempts_left' => $entitlement['attempts_left'],
            'unlocks_at' => $entitlement['unlocks_at']?->toIso8601String(),
        ];
    }
}
