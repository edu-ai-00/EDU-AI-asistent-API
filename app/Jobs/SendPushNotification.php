<?php

namespace App\Jobs;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\PushDevice;
use App\Models\UserNotification;
use App\Notifications\NotificationService;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Notifications\StatusTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Deliver one notification's push to every enabled device of its recipient
 * (NOTIF-2, spec §6.1).
 *
 * - Any device accepting it → `sent`. A dead token disables its device.
 * - Transient failures (timeouts, 429, 5xx) are retried with the configured
 *   backoff; out of attempts, or nothing retryable left → `failed` + reason.
 * - Idempotent: a notification whose push already went out is never sent
 *   again, so a worker that crashed after FCM accepted the message (and the
 *   job being retried) does not buzz the phone twice. A cache lock keeps two
 *   workers from sending the same notification at the same moment.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public int $userNotificationId)
    {
        $this->tries = max(1, (int) config('notifications.push.tries', 3));
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('notifications.push.backoff', [60, 300, 900])));
    }

    public function handle(PushSender $sender, StatusTracker $tracker): void
    {
        $lock = Cache::lock('notif:push:' . $this->userNotificationId, 120);
        if (!$lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->deliver($sender, $tracker);
        } finally {
            $lock->release();
        }
    }

    private function deliver(PushSender $sender, StatusTracker $tracker): void
    {
        $notification = UserNotification::find($this->userNotificationId);

        if ($notification === null || $notification->pushAlreadySent()) {
            return;
        }

        if (in_array($notification->push_status, [UserNotification::PUSH_FAILED, UserNotification::PUSH_SKIPPED], true)) {
            return;
        }

        // Quiet-hours deferral picked up early (a sync queue ignores delays,
        // a worker clock may run ahead): wait out the rest.
        if ($notification->push_status === UserNotification::PUSH_DEFERRED
            && $notification->deferred_until !== null
            && $notification->deferred_until->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($notification->deferred_until, true)));

            return;
        }

        if (!$sender->isConfigured()) {
            $this->skip($notification, NotificationService::REASON_PUSH_UNAVAILABLE);

            return;
        }

        // The user may have opted out while this sat in the queue.
        $type = NotificationType::find($notification->notification_type_id);
        if (!($type?->is_mandatory) && !NotificationPreference::forUser($notification->user_id)->push_enabled) {
            $this->skip($notification, NotificationService::REASON_PUSH_DISABLED);

            return;
        }

        $devices = PushDevice::where('user_id', $notification->user_id)->enabled()->get();
        if ($devices->isEmpty()) {
            $this->skip($notification, NotificationService::REASON_NO_DEVICE);

            return;
        }

        if ($notification->push_status === UserNotification::PUSH_DEFERRED) {
            $notification->forceFill(['push_status' => UserNotification::PUSH_QUEUED])->save();
            $tracker->transition($notification, UserNotification::STATUS_QUEUED);
        }

        $message = PushMessage::fromNotification($notification);
        $accepted = 0;
        $retryable = [];
        $errors = [];

        foreach ($devices as $device) {
            $result = $sender->send($device, $message);

            if ($result->ok) {
                $accepted++;

                continue;
            }

            if ($result->invalidToken) {
                $device->disable('invalid_token: ' . $result->error);
                $errors[] = 'invalid_token';

                continue;
            }

            if ($result->retryable) {
                $retryable[] = (string) $result->error;
            } else {
                $errors[] = (string) $result->error;
            }
        }

        if ($accepted > 0) {
            $notification->forceFill([
                'push_status' => UserNotification::PUSH_SENT,
                'failure_reason' => null,
            ])->save();
            $tracker->transition($notification, UserNotification::STATUS_SENT, [
                'devices' => $devices->count(),
                'accepted' => $accepted,
            ]);

            return;
        }

        if ($retryable !== [] && $this->attempts() < $this->tries) {
            $backoff = $this->backoff();
            $delay = $backoff[$this->attempts() - 1] ?? (end($backoff) ?: 60);

            Log::channel('notifications')->info('notifications.push_retry', [
                'notification_id' => $notification->uuid,
                'attempt' => $this->attempts(),
                'errors' => $retryable,
            ]);

            $this->release($delay);

            return;
        }

        $this->markFailed($notification, $tracker, implode('; ', array_unique([...$errors, ...$retryable])) ?: 'push_failed');
    }

    /** Final failure after all retries, or an exception the worker gave up on. */
    public function failed(?\Throwable $exception): void
    {
        $notification = UserNotification::find($this->userNotificationId);

        Log::channel('notifications')->error('notifications.push_job_failed', [
            'notification_id' => $notification?->uuid,
            'user_notification_id' => $this->userNotificationId,
            'error' => $exception?->getMessage(),
        ]);

        if ($notification !== null && !$notification->pushAlreadySent()) {
            $this->markFailed(
                $notification,
                app(StatusTracker::class),
                'job_failed: ' . ($exception ? class_basename($exception) . ': ' . $exception->getMessage() : 'unknown'),
            );
        }
    }

    private function markFailed(UserNotification $notification, StatusTracker $tracker, string $reason): void
    {
        $reason = mb_substr($reason, 0, 255);

        $notification->forceFill([
            'push_status' => UserNotification::PUSH_FAILED,
            'failure_reason' => $reason,
        ])->save();

        $tracker->transition($notification, UserNotification::STATUS_FAILED, ['reason' => mb_substr($reason, 0, 64), 'error' => $reason]);
    }

    private function skip(UserNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'push_status' => UserNotification::PUSH_SKIPPED,
            'failure_reason' => $reason,
        ])->save();

        NotificationEvent::forNotification($notification, NotificationEvent::DECISION_SUPPRESS, $reason, [
            'channel' => NotificationType::CHANNEL_PUSH,
            'at' => 'send',
        ]);
    }
}
