<?php

namespace App\Jobs;

use App\Models\NotificationBroadcast;
use App\Models\UserNotification;
use App\Notifications\BroadcastService;
use App\Notifications\NotificationEvents;
use App\Notifications\NotificationResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one chunk of a broadcast's recipient snapshot (NOTIF-10, spec
 * §6.1): `broadcast.sent` is evaluated right here, in the job, through the
 * rule engine — NotificationEvents::evaluateNow(), narrowed to the
 * broadcast's own channels — and the outcome is added to the broadcast's
 * counters. The job that finishes the last chunk marks the broadcast sent.
 *
 * Counting is idempotent per chunk (a cache marker): a retried job never
 * counts its recipients twice. Who was accepted is read back from
 * user_notifications rather than from this attempt's decisions, so a retry
 * after a partial run still credits the recipients the first attempt reached
 * (the retry's own sends are deduplicated, i.e. suppressed).
 *
 * The `delivered_count` column holds those accepted recipients — handed to
 * the pipeline, not yet on a device. What the admin shows as delivered is
 * NotificationBroadcast::deliveredCounts() (BR-PJEVXV).
 */
class ProcessBroadcastChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** How long a chunk's "already counted" marker is kept. */
    private const MARKER_DAYS = 7;

    /** @param  list<int>  $userIds */
    public function __construct(
        public int $broadcastId,
        public array $userIds,
        public int $chunkIndex = 0,
    ) {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BroadcastService $broadcasts): void
    {
        $broadcast = NotificationBroadcast::find($this->broadcastId);

        if ($broadcast === null || $broadcast->status !== NotificationBroadcast::STATUS_SENDING) {
            Log::channel('notifications')->warning('notifications.broadcast_chunk_skipped', [
                'broadcast_id' => $this->broadcastId,
                'chunk' => $this->chunkIndex,
                'status' => $broadcast?->status,
            ]);

            return;
        }

        if (Cache::has($this->marker())) {
            return;
        }

        $outcomes = NotificationEvents::evaluateNow(
            'broadcast.sent',
            $broadcasts->eventFields($broadcast),
            $this->userIds,
            (array) $broadcast->channels,
        );

        [$delivered, $suppressed, $failed] = $this->tally($outcomes);

        if ($outcomes === []) {
            // No rule ran at all (deactivated since the send was accepted).
            Log::channel('notifications')->warning('notifications.broadcast_chunk_no_rule', [
                'broadcast_id' => $this->broadcastId,
                'chunk' => $this->chunkIndex,
            ]);
        }

        $this->countOnce($delivered, $suppressed, $failed);
    }

    /** Out of attempts: whatever this chunk did not deliver counts as failed. */
    public function failed(?\Throwable $exception): void
    {
        Log::channel('notifications')->error('notifications.broadcast_chunk_failed', [
            'broadcast_id' => $this->broadcastId,
            'chunk' => $this->chunkIndex,
            'recipients' => count($this->userIds),
            'error' => $exception?->getMessage(),
        ]);

        try {
            [$delivered, , $failed] = $this->tally([]);
            $this->countOnce($delivered, 0, $failed);
        } catch (\Throwable $e) {
            Log::channel('notifications')->error('notifications.broadcast_chunk_count_failed', [
                'broadcast_id' => $this->broadcastId,
                'chunk' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add to the counters and close the broadcast when every recipient has
     * been processed. Atomic increments; only one caller wins the close.
     */
    public static function count(int $broadcastId, int $delivered, int $suppressed, int $failed): void
    {
        $processed = $delivered + $suppressed + $failed;
        if ($processed === 0) {
            return;
        }

        NotificationBroadcast::whereKey($broadcastId)->incrementEach([
            'processed_count' => $processed,
            'delivered_count' => $delivered,
            'suppressed_count' => $suppressed,
            'failed_count' => $failed,
        ]);

        $broadcast = NotificationBroadcast::find($broadcastId);
        if ($broadcast === null || $broadcast->processed_count < $broadcast->recipient_count) {
            return;
        }

        // Nothing reached anyone at all → failed; otherwise sent, with the
        // per-recipient outcome in the counters.
        $status = $broadcast->failed_count >= $broadcast->recipient_count
            ? NotificationBroadcast::STATUS_FAILED
            : NotificationBroadcast::STATUS_SENT;

        NotificationBroadcast::whereKey($broadcastId)
            ->where('status', NotificationBroadcast::STATUS_SENDING)
            ->update([
                'status' => $status,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function countOnce(int $delivered, int $suppressed, int $failed): void
    {
        if (!Cache::add($this->marker(), true, now()->addDays(self::MARKER_DAYS))) {
            return;
        }

        self::count($this->broadcastId, $delivered, $suppressed, $failed);
    }

    /**
     * Per recipient: accepted when a notification of this broadcast exists
     * for them, suppressed when a rule suppressed it (duplicate, limits, no
     * channel) or left them out of its audience (a user.* condition), failed
     * otherwise (rejected, no rule, errors).
     *
     * @param  list<array{rule_id: int, matched: bool, result: ?NotificationResult, error: ?string, skipped?: list<int>}>  $outcomes
     * @return array{0: int, 1: int, 2: int}
     */
    private function tally(array $outcomes): array
    {
        $delivered = UserNotification::where('broadcast_id', $this->broadcastId)
            ->where('is_test', false)
            ->whereIn('user_id', $this->userIds)
            ->distinct()
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $suppressed = [];
        foreach ($outcomes as $outcome) {
            foreach ($outcome['skipped'] ?? [] as $userId) {
                $suppressed[(int) $userId] = true;
            }

            foreach ($outcome['result']?->decisions ?? [] as $decision) {
                if ($decision['decision'] === NotificationResult::SUPPRESS) {
                    $suppressed[(int) $decision['user_id']] = true;
                }
            }
        }

        $counts = [0, 0, 0];
        foreach (array_unique($this->userIds) as $userId) {
            if (isset($delivered[$userId])) {
                $counts[0]++;
            } elseif (isset($suppressed[$userId])) {
                $counts[1]++;
            } else {
                $counts[2]++;
            }
        }

        return $counts;
    }

    private function marker(): string
    {
        return 'notif:broadcast:' . $this->broadcastId . ':chunk:' . $this->chunkIndex . ':counted';
    }
}
