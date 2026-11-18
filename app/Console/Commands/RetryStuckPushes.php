<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use App\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-queue pushes that are stuck (spec §2.2 step 8, §6.2) — every 10 minutes.
 *
 *   - queue_unavailable: the push never reached the queue (the queue was
 *     down when the notification was created) — re-dispatched right away;
 *   - a quiet-hours deferral whose deferred_until is more than GRACE_MINUTES
 *     past and still deferred — its delayed job got lost.
 *
 * SendPushNotification is idempotent (never re-sends a sent push), so an
 * extra dispatch is safe. A re-dispatched deferral's deferred_until moves to
 * now, so a queue that is merely slow is not flooded every run. Stops at the
 * first failed dispatch: the queue is still down, the next run retries.
 */
class RetryStuckPushes extends Command
{
    protected $signature = 'notifications:retry-stuck {--limit=2000 : Most pushes re-queued in one run}';

    protected $description = 'Re-dispatch push notifications stuck as deferred (queue outage, lost delayed job)';

    public const GRACE_MINUTES = 15;

    public function handle(NotificationService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $overdue = now()->subMinutes(self::GRACE_MINUTES);
        $retried = 0;
        $queueDown = false;

        UserNotification::where('push_status', UserNotification::PUSH_DEFERRED)
            ->where(function ($q) use ($overdue) {
                $q->where('failure_reason', NotificationService::REASON_QUEUE_UNAVAILABLE)
                    ->orWhere('deferred_until', '<=', $overdue)
                    ->orWhereNull('deferred_until');
            })
            ->chunkById(200, function (Collection $notifications) use ($service, $limit, &$retried, &$queueDown) {
                foreach ($notifications as $notification) {
                    if ($retried >= $limit) {
                        return false;
                    }

                    if (!$service->retryPush($notification)) {
                        $queueDown = true;

                        return false;
                    }

                    if ($notification->deferred_until === null || $notification->deferred_until->isPast()) {
                        $notification->forceFill(['deferred_until' => now()])->save();
                    }

                    $retried++;
                }

                return true;
            });

        $this->info("Retry stuck pushes: {$retried} re-queued" . ($queueDown ? ' (queue still unavailable, stopped)' : '') . '.');

        return self::SUCCESS;
    }
}
