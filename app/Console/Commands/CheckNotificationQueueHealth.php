<?php

namespace App\Console\Commands;

use App\Notifications\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * notifications:queue-health (spec §6.2) — every 5 minutes.
 *
 * Logs a warning on the `notifications` channel when more than
 * config('notifications.queue_warning_pending') jobs are waiting on the
 * notifications queue (no worker, or one that cannot keep up), and when
 * failed_jobs has grown since the previous run.
 */
class CheckNotificationQueueHealth extends Command
{
    protected $signature = 'notifications:queue-health';

    protected $description = 'Warn when the notifications queue backs up or jobs start failing';

    public const FAILED_CACHE_KEY = 'notifications:queue_health:failed';

    public function handle(): int
    {
        $health = QueueHealth::snapshot();
        $threshold = (int) config('notifications.queue_warning_pending', 1000);

        if ($health['pending_notifications'] > $threshold) {
            Log::channel('notifications')->warning('notifications.queue_backlog', $health + ['threshold' => $threshold]);
        }

        $previous = Cache::get(self::FAILED_CACHE_KEY);
        if ($previous !== null && $health['failed'] > (int) $previous) {
            Log::channel('notifications')->warning('notifications.queue_failed_jobs_grew', $health + [
                'previous_failed' => (int) $previous,
                'new_failed' => $health['failed'] - (int) $previous,
            ]);
        }
        Cache::forever(self::FAILED_CACHE_KEY, $health['failed']);

        $this->info(sprintf(
            'Queue health: %d pending (%d notifications), %d failed, oldest %s s, %d deferred pushes.',
            $health['pending'],
            $health['pending_notifications'],
            $health['failed'],
            $health['oldest_pending_seconds'] ?? '-',
            $health['deferred_push'],
        ));

        return self::SUCCESS;
    }
}
