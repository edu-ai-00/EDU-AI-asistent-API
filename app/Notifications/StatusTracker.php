<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The only thing that moves a notification through its states (NOTIF-8,
 * spec §2.3):
 *
 *   created → queued → sent → delivered → viewed → clicked → app_opened
 *
 * plus `failed`, and `target_action` as a timestamp-only marker.
 *
 * - `status` holds the furthest state reached and never moves backwards;
 *   `failed` only overrides created/queued, and a later delivery report
 *   (sent or further) overrides `failed`.
 * - Each state's `*_at` is written the first time only, and that first time
 *   also writes the notification_events row — so replayed or out-of-order
 *   reports from the app (offline batches) are harmless.
 * - clicked and app_opened also mark the feed item read.
 *
 * The writes are conditional UPDATEs, so two workers reporting the same
 * state at once still log it once.
 */
class StatusTracker
{
    public const TARGET_ACTION = 'target_action';

    private const TIMESTAMPED = [
        UserNotification::STATUS_QUEUED => 'queued_at',
        UserNotification::STATUS_SENT => 'sent_at',
        UserNotification::STATUS_DELIVERED => 'delivered_at',
        UserNotification::STATUS_VIEWED => 'viewed_at',
        UserNotification::STATUS_CLICKED => 'clicked_at',
        UserNotification::STATUS_APP_OPENED => 'app_opened_at',
        UserNotification::STATUS_FAILED => 'failed_at',
        self::TARGET_ACTION => 'target_action_at',
    ];

    /**
     * Apply a state to the notification. Returns true when this was the
     * first time the state was reported (and an event row was written).
     *
     * @param  array<string, mixed>  $meta
     */
    public function transition(UserNotification $notification, string $state, array $meta = [], ?CarbonInterface $at = null): bool
    {
        $column = self::TIMESTAMPED[$state] ?? null;
        if ($column === null) {
            throw new \InvalidArgumentException("Unknown notification state {$state}");
        }

        $at = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        $first = UserNotification::whereKey($notification->id)
            ->whereNull($column)
            ->update([$column => $at, 'updated_at' => now()]) === 1;

        if ($first) {
            $notification->setAttribute($column, $at);
        }

        if (in_array($state, [UserNotification::STATUS_CLICKED, UserNotification::STATUS_APP_OPENED], true)) {
            $marked = UserNotification::whereKey($notification->id)
                ->whereNull('read_at')
                ->update(['read_at' => $at]);
            if ($marked === 1) {
                $notification->setAttribute('read_at', $at);
            }
        }

        if ($state !== self::TARGET_ACTION) {
            $this->advanceStatus($notification, $state);
        }

        // The in-memory model now mirrors the row; nothing left to save.
        $written = array_intersect(
            ['status', 'read_at', ...array_values(self::TIMESTAMPED)],
            array_keys($notification->getAttributes()),
        );
        if ($written !== []) {
            $notification->syncOriginalAttributes(array_values($written));
        }

        if ($first) {
            $reason = isset($meta['reason']) ? (string) $meta['reason'] : null;
            unset($meta['reason']);
            NotificationEvent::forNotification($notification, $state, $reason, $meta, $at);
        }

        return $first;
    }

    /**
     * Move `status` forward to $state if that is further than where it is.
     */
    private function advanceStatus(UserNotification $notification, string $state): void
    {
        if ($state === UserNotification::STATUS_FAILED) {
            $from = [UserNotification::STATUS_CREATED, UserNotification::STATUS_QUEUED];
        } else {
            $rank = UserNotification::statusRank($state);
            $from = array_values(array_filter(
                UserNotification::STATUS_ORDER,
                fn (string $s) => UserNotification::statusRank($s) < $rank,
            ));

            // A delivery report beats an earlier failure.
            if ($rank >= UserNotification::statusRank(UserNotification::STATUS_SENT)) {
                $from[] = UserNotification::STATUS_FAILED;
            }
        }

        if ($from === []) {
            return;
        }

        $moved = UserNotification::whereKey($notification->id)
            ->whereIn('status', $from)
            ->update(['status' => $state]);

        if ($moved === 1) {
            $notification->setAttribute('status', $state);
        }
    }
}
