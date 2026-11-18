<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of notification the system can send (NOTIF-1, NOTIF-5, NOTIF-9).
 *
 * `key` is the type_key everything else refers to and never changes after
 * the seed; everything else is admin-editable. Templates use `{{variable}}`
 * placeholders drawn from `variables` — see App\Notifications\TemplateRenderer.
 */
class NotificationType extends Model
{
    public const CATEGORIES = ['provoz', 'navyk', 'pokrok', 'aktivace'];

    public const ROLES = ['student', 'teacher', 'all'];

    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_FEED = 'feed';
    public const CHANNELS = [self::CHANNEL_PUSH, self::CHANNEL_FEED];

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'target_role',
        'is_active',
        'is_mandatory',
        'is_system',
        'manual_allowed',
        'channels',
        'respect_quiet_hours',
        'frequency_limit',
        'frequency_period_hours',
        'dedup_window_hours',
        'push_title',
        'push_body',
        'feed_title',
        'feed_body',
        'variables',
        'deep_link',
        'target_action',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_system' => 'boolean',
            'manual_allowed' => 'boolean',
            'respect_quiet_hours' => 'boolean',
            'channels' => 'array',
            'variables' => 'array',
            'frequency_limit' => 'integer',
            'frequency_period_hours' => 'integer',
            'dedup_window_hours' => 'integer',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(NotificationRule::class);
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /** @return list<string> */
    public function channelList(): array
    {
        return array_values(array_intersect(self::CHANNELS, (array) $this->channels));
    }

    /**
     * Declared variables keyed by variable key.
     *
     * @return array<string, array{key: string, label?: string, sample?: mixed, fallback?: mixed, required?: bool}>
     */
    public function variableMap(): array
    {
        $map = [];

        foreach ((array) $this->variables as $variable) {
            if (is_array($variable) && isset($variable['key']) && is_string($variable['key'])) {
                $map[$variable['key']] = $variable;
            }
        }

        return $map;
    }

    public function declaresVariable(string $key): bool
    {
        return array_key_exists($key, $this->variableMap());
    }

    /**
     * The four text templates plus the deep link, as the renderer and the
     * validator take them.
     *
     * @return array{push_title: string, push_body: string, feed_title: string, feed_body: string, deep_link: ?string}
     */
    public function templates(): array
    {
        return [
            'push_title' => (string) $this->push_title,
            'push_body' => (string) $this->push_body,
            'feed_title' => (string) $this->feed_title,
            'feed_body' => (string) $this->feed_body,
            'deep_link' => $this->deep_link,
        ];
    }

    /** Does a user with this role fall within the type's target role? */
    public static function roleMatches(string $targetRole, ?string $userRole): bool
    {
        return match ($targetRole) {
            'student' => $userRole === 'student',
            // Admins are on the team too — a teacher-facing notice reaches them.
            'teacher' => in_array($userRole, ['teacher', 'admin'], true),
            default => true,
        };
    }
}
