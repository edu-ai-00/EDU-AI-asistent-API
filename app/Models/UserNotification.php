<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One notification for one recipient — also a row of the in-app feed.
 *
 * `uuid` (a ULID) is the public notification_id: it is what the app, deep
 * links and push data carry. The integer id never leaves the server except in
 * admin history views.
 *
 * `status` is the furthest state reached and only App\Notifications\
 * StatusTracker moves it, so it never goes backwards and every move is logged.
 */
class UserNotification extends Model
{
    public const SOURCE_RULE = 'rule';
    public const SOURCE_BROADCAST = 'broadcast';
    public const SOURCE_TEST = 'test';
    public const SOURCES = [self::SOURCE_RULE, self::SOURCE_BROADCAST, self::SOURCE_TEST];

    public const STATUS_CREATED = 'created';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_CLICKED = 'clicked';
    public const STATUS_APP_OPENED = 'app_opened';
    public const STATUS_FAILED = 'failed';

    /** Forward order of the status model (NOTIF-8); failed sits outside it. */
    public const STATUS_ORDER = [
        self::STATUS_CREATED,
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_DELIVERED,
        self::STATUS_VIEWED,
        self::STATUS_CLICKED,
        self::STATUS_APP_OPENED,
    ];

    public const PUSH_DEFERRED = 'deferred';
    public const PUSH_QUEUED = 'queued';
    public const PUSH_SENT = 'sent';
    public const PUSH_DELIVERED = 'delivered';
    public const PUSH_FAILED = 'failed';
    public const PUSH_SKIPPED = 'skipped';

    protected $fillable = [
        'uuid',
        'user_id',
        'notification_type_id',
        'type_key',
        'category',
        'source',
        'rule_id',
        'broadcast_id',
        'is_test',
        'dedup_key',
        'channels',
        'in_feed',
        'title',
        'body',
        'push_title',
        'push_body',
        'deep_link',
        'payload',
        'status',
        'push_status',
        'failure_reason',
        'deferred_until',
        'queued_at',
        'sent_at',
        'delivered_at',
        'viewed_at',
        'clicked_at',
        'app_opened_at',
        'failed_at',
        'target_action_at',
        'read_at',
        'target_action',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'notification_type_id' => 'integer',
            'rule_id' => 'integer',
            'broadcast_id' => 'integer',
            'is_test' => 'boolean',
            'in_feed' => 'boolean',
            'channels' => 'array',
            'payload' => 'array',
            'deferred_until' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'viewed_at' => 'datetime',
            'clicked_at' => 'datetime',
            'app_opened_at' => 'datetime',
            'failed_at' => 'datetime',
            'target_action_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserNotification $notification) {
            if (empty($notification->uuid)) {
                $notification->uuid = (string) Str::ulid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationEvent::class, 'notification_id');
    }

    public function scopeInFeed(Builder $query): Builder
    {
        return $query->where('in_feed', true);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, (array) $this->channels, true);
    }

    /** Position in STATUS_ORDER; failed ranks just above queued. */
    public static function statusRank(?string $status): int
    {
        if ($status === self::STATUS_FAILED) {
            return 1;
        }

        $rank = array_search($status, self::STATUS_ORDER, true);

        return $rank === false ? 0 : (int) $rank;
    }

    /** Push already went out (or further) — a retry must not send it again. */
    public function pushAlreadySent(): bool
    {
        return in_array($this->push_status, [self::PUSH_SENT, self::PUSH_DELIVERED], true)
            || $this->sent_at !== null;
    }
}
