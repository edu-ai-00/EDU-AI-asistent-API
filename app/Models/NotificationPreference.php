<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's notification settings, keyed by user_id and created lazily.
 * Null columns fall back to config('notifications.*'); the effective*()
 * helpers are the one place that fallback is spelled.
 *
 * Times are stored as HH:MM:SS (what Postgres' time type hands back) and
 * exposed as HH:MM.
 */
class NotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'push_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'reminder_time',
        'timezone',
    ];

    protected $attributes = [
        'push_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'push_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "07:00:00" / "07:00" → "07:00"; null stays null. */
    public static function hhmm(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }

    /** "07:00" → "07:00:00" for storage; null stays null. */
    public static function toStorageTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?: self::defaultTimezone();
    }

    public function effectiveQuietHoursStart(): string
    {
        return self::hhmm($this->quiet_hours_start) ?? self::defaultQuietHours()['start'];
    }

    public function effectiveQuietHoursEnd(): string
    {
        return self::hhmm($this->quiet_hours_end) ?? self::defaultQuietHours()['end'];
    }

    public static function defaultTimezone(): string
    {
        return (string) config('notifications.default_timezone', 'Europe/Prague');
    }

    /** @return array{start: string, end: string} */
    public static function defaultQuietHours(): array
    {
        $quiet = (array) config('notifications.quiet_hours', []);

        return [
            'start' => (string) ($quiet['start'] ?? '20:00'),
            'end' => (string) ($quiet['end'] ?? '07:00'),
        ];
    }

    /** The stored row, or an unsaved one carrying the defaults. */
    public static function forUser(int $userId): self
    {
        return self::find($userId) ?? new self(['user_id' => $userId, 'push_enabled' => true]);
    }
}
