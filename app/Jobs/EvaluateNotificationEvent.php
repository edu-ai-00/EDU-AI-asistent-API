<?php

namespace App\Jobs;

use App\Notifications\RuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the active rules for one domain event (spec §3.1, §6.1). Dispatched
 * by NotificationEvents::emit() so the request that produced the event never
 * waits for notifications. A retry is safe: every send is deduplicated on
 * the event's dedup key.
 */
class EvaluateNotificationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $fields  validated against EventCatalog
     * @param  list<int>  $userIds
     */
    public function __construct(
        public string $eventKey,
        public array $fields,
        public array $userIds,
        public ?string $dedupKey = null,
    ) {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RuleEngine $engine): void
    {
        $engine->evaluate($this->eventKey, $this->fields, $this->userIds, $this->dedupKey);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('notifications')->error('notifications.event_evaluation_failed', [
            'event' => $this->eventKey,
            'recipients' => count($this->userIds),
            'error' => $exception?->getMessage(),
        ]);
    }
}
