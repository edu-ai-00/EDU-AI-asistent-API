<?php

namespace App\Jobs;

use App\Notifications\NotificationRequest;
use App\Notifications\NotificationService;
use App\Notifications\NotificationValidationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Replays a NotificationRequest at its deliverAt time — a request with a
 * future deliverAt, or a rule with delay_minutes (spec §6.1). The request
 * travels as a plain array and re-enters NotificationService::send() as if
 * it had just arrived, so dedup, limits and quiet hours are judged at
 * delivery time, not at scheduling time.
 */
class DispatchNotificationRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  array<string, mixed>  $request  NotificationRequest::toArray() */
    public function __construct(public array $request)
    {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationService $service): void
    {
        $request = NotificationRequest::fromArray($this->request)->withoutDeliverAt();

        try {
            $service->send($request);
        } catch (NotificationValidationException $e) {
            // The type was renamed away or the like since scheduling — a retry
            // cannot fix that.
            Log::channel('notifications')->warning('notifications.delayed_request_invalid', [
                'type_key' => $request->typeKey,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('notifications')->error('notifications.delayed_request_failed', [
            'type_key' => $this->request['type_key'] ?? null,
            'recipients' => count((array) ($this->request['recipient_ids'] ?? [])),
            'error' => $exception?->getMessage(),
        ]);
    }
}
