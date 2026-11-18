<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A manual notification an admin sends to schools / classes (NOTIF-10).
 * Delivery goes through the rule engine: sending emits `broadcast.sent` for
 * each chunk of the recipient snapshot (notification_broadcast_recipients).
 */
class NotificationBroadcast extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'title',
        'body',
        'type_key',
        'channels',
        'targeting',
        'targeting_snapshot',
        'deadline_at',
        'status',
        'recipient_count',
        'processed_count',
        'delivered_count',
        'suppressed_count',
        'failed_count',
        'created_by',
        'updated_by',
        'sent_by',
        'sent_at',
        'completed_at',
        'archived_at',
        'archived_by',
        'deadline_reminded_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'targeting' => 'array',
            'targeting_snapshot' => 'array',
            'deadline_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'deadline_reminded_at' => 'datetime',
            'recipient_count' => 'integer',
            'processed_count' => 'integer',
            'delivered_count' => 'integer',
            'suppressed_count' => 'integer',
            'failed_count' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'sent_by' => 'integer',
            'archived_by' => 'integer',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationBroadcastRecipient::class, 'broadcast_id');
    }

    public function type(): ?NotificationType
    {
        return NotificationType::where('key', $this->type_key)->first();
    }

    /**
     * Recipients each broadcast actually reached, by broadcast id: those
     * whose notification of it has delivered_at — the app synced it in the
     * feed or reported the push arriving (StatusTracker).
     *
     * The stored delivered_count is not that: a chunk counts a recipient
     * there as soon as their notification is created, i.e. accepted for
     * delivery (BR-PJEVXV). The deadline reminder carries the broadcast_id
     * too, but under its own type, so only the broadcast's type counts.
     *
     * @param  iterable<self>  $broadcasts
     * @return array<int, int>
     */
    public static function deliveredCounts(iterable $broadcasts): array
    {
        return self::recipientCounts($broadcasts, fn (QueryBuilder $q) => $q
            ->whereNotNull('user_notifications.delivered_at'));
    }

    /**
     * Recipients who have seen each broadcast, by broadcast id (BR-7ETNQE):
     * their notification of it reached `viewed` or further — the feed row
     * came on screen, or they opened the push (clicked / app_opened, with
     * the feed row perhaps never shown). Not read_at: "mark all read" sets
     * that on items never seen. Same scope as deliveredCounts().
     *
     * @param  iterable<self>  $broadcasts
     * @return array<int, int>
     */
    public static function readCounts(iterable $broadcasts): array
    {
        return self::recipientCounts($broadcasts, fn (QueryBuilder $q) => $q
            ->where(fn (QueryBuilder $seen) => $seen
                ->whereNotNull('user_notifications.viewed_at')
                ->orWhereNotNull('user_notifications.clicked_at')
                ->orWhereNotNull('user_notifications.app_opened_at')));
    }

    /**
     * Distinct recipients per sent broadcast whose notification of it (its
     * own type, no tests) matches $condition.
     *
     * @param  iterable<self>  $broadcasts
     * @param  \Closure(QueryBuilder): mixed  $condition
     * @return array<int, int>
     */
    private static function recipientCounts(iterable $broadcasts, \Closure $condition): array
    {
        $ids = [];
        foreach ($broadcasts as $broadcast) {
            if ($broadcast->sent_at !== null) {
                $ids[] = $broadcast->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return UserNotification::query()
            ->toBase()
            ->join('notification_broadcasts', 'notification_broadcasts.id', '=', 'user_notifications.broadcast_id')
            ->whereIn('user_notifications.broadcast_id', $ids)
            ->whereColumn('user_notifications.type_key', 'notification_broadcasts.type_key')
            ->where('user_notifications.is_test', false)
            ->where($condition)
            ->groupBy('user_notifications.broadcast_id')
            ->selectRaw('user_notifications.broadcast_id, COUNT(DISTINCT user_notifications.user_id) as recipients')
            ->pluck('recipients', 'broadcast_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])
            ->all();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
