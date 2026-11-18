<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log row: one per-recipient decision or one state transition
 * (NOTIF-8). Written only through record(); never updated.
 */
class NotificationEvent extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_DELIVER = 'decision.deliver';
    public const DECISION_DEFER = 'decision.defer';
    public const DECISION_SUPPRESS = 'decision.suppress';
    public const DECISION_REJECT = 'decision.reject';

    public const DECISIONS = [
        self::DECISION_DELIVER,
        self::DECISION_DEFER,
        self::DECISION_SUPPRESS,
        self::DECISION_REJECT,
    ];

    protected $fillable = [
        'notification_id',
        'user_id',
        'type_key',
        'rule_id',
        'broadcast_id',
        'event',
        'reason',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'user_id' => 'integer',
            'rule_id' => 'integer',
            'broadcast_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'notification_id');
    }

    /** No foreign key behind user_id: null once the account is gone. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an event about an existing notification, copying its identifying
     * columns so the row reads on its own in the history.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function forNotification(
        UserNotification $notification,
        string $event,
        ?string $reason = null,
        array $meta = [],
        mixed $at = null,
    ): self {
        return self::record([
            'notification_id' => $notification->id,
            'user_id' => $notification->user_id,
            'type_key' => $notification->type_key,
            'rule_id' => $notification->rule_id,
            'broadcast_id' => $notification->broadcast_id,
            'event' => $event,
            'reason' => $reason,
            'meta' => $meta ?: null,
            'created_at' => $at ?? now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function record(array $attributes): self
    {
        $attributes['created_at'] ??= now();

        if (isset($attributes['reason']) && is_string($attributes['reason'])) {
            $attributes['reason'] = mb_substr($attributes['reason'], 0, 64);
        }

        return self::create($attributes);
    }
}
