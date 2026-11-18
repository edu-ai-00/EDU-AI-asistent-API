<?php

namespace App\Notifications;

use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * How the notification queue is doing (NOTIF-2, spec §5.2 queue-health,
 * §6.2 notifications:queue-health).
 *
 *   pending                 ready jobs on the notifications and default queues
 *   pending_notifications   ready jobs on the notifications queue
 *   failed                  rows in failed_jobs
 *   oldest_pending_seconds  age of the oldest ready notifications job (null: none)
 *   deferred_push           notifications whose push waits (quiet hours, queue outage)
 *
 * "Ready" leaves out delayed jobs: a night's worth of quiet-hours pushes
 * sits in the queue on purpose and is not a backlog.
 *
 * Each figure is read on its own and degrades to 0 / null when its source
 * cannot answer (a driver without size methods, no failed_jobs table), so
 * the admin strip never errors out.
 */
class QueueHealth
{
    /**
     * @return array{pending: int, pending_notifications: int, failed: int, oldest_pending_seconds: ?int, deferred_push: int}
     */
    public static function snapshot(): array
    {
        $queue = (string) config('notifications.queue', 'notifications');

        $pendingNotifications = self::pendingSize($queue);
        $pendingDefault = self::pendingSize(null);

        return [
            'pending' => $pendingNotifications + $pendingDefault,
            'pending_notifications' => $pendingNotifications,
            'failed' => self::failedCount(),
            'oldest_pending_seconds' => self::oldestPendingSeconds($queue),
            'deferred_push' => self::safe(fn () => UserNotification::where('push_status', UserNotification::PUSH_DEFERRED)->count(), 0),
        ];
    }

    private static function pendingSize(?string $queue): int
    {
        return (int) self::safe(function () use ($queue) {
            $connection = Queue::connection();

            if ($queue === null && method_exists($connection, 'getQueue')) {
                $default = $connection->getQueue(null);
                // The default queue *is* the notifications queue — do not count it twice.
                if ($default === config('notifications.queue', 'notifications')) {
                    return 0;
                }
            }

            if (method_exists($connection, 'pendingSize')) {
                return $connection->pendingSize($queue);
            }

            return $connection->size($queue);
        }, 0);
    }

    private static function oldestPendingSeconds(string $queue): ?int
    {
        return self::safe(function () use ($queue) {
            $connection = Queue::connection();
            if (!method_exists($connection, 'creationTimeOfOldestPendingJob')) {
                return null;
            }

            $created = $connection->creationTimeOfOldestPendingJob($queue);
            if ($created === null) {
                return null;
            }

            return max(0, now()->getTimestamp() - (int) $created);
        }, null);
    }

    private static function failedCount(): int
    {
        return (int) self::safe(function () {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database') ?: config('database.default');

            return DB::connection($connection)->table($table)->count();
        }, 0);
    }

    private static function safe(callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            Log::channel('notifications')->warning('notifications.queue_health_unavailable', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $fallback;
        }
    }
}
