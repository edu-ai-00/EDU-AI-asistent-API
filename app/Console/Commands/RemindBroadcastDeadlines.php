<?php

namespace App\Console\Commands;

use App\Models\NotificationBroadcast;
use App\Models\NotificationBroadcastRecipient;
use App\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * broadcast.deadline_approaching (spec §3.3, §6.2) — every 15 minutes.
 *
 * A sent broadcast whose deadline_at is within the next 24 hours gets one
 * reminder to its recipient snapshot. deadline_reminded_at is claimed with
 * a conditional update before anything is emitted, so overlapping runs
 * cannot remind twice; if no chunk could be handed to the queue at all, the
 * claim is released and the next run tries again.
 *
 * A broadcast sent less than 24 hours before its own deadline is not
 * reminded: the message itself is the fresh news.
 */
class RemindBroadcastDeadlines extends Command
{
    protected $signature = 'notifications:broadcast-deadlines';

    protected $description = 'Emit broadcast.deadline_approaching for sent broadcasts due within 24 hours';

    private const WINDOW_HOURS = 24;

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $reminded = 0;

        $due = NotificationBroadcast::where('status', NotificationBroadcast::STATUS_SENT)
            ->whereNull('deadline_reminded_at')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '>', $now)
            ->where('deadline_at', '<=', $now->addHours(self::WINDOW_HOURS))
            ->orderBy('deadline_at')
            ->get();

        foreach ($due as $broadcast) {
            if ($broadcast->sent_at !== null
                && $broadcast->sent_at->greaterThan($broadcast->deadline_at->copy()->subHours(self::WINDOW_HOURS))) {
                continue;
            }

            if ($this->remind($broadcast, $now)) {
                $reminded++;
            }
        }

        $this->info("Broadcast deadlines: {$reminded} broadcast(s) reminded.");

        return self::SUCCESS;
    }

    private function remind(NotificationBroadcast $broadcast, CarbonImmutable $now): bool
    {
        $claimed = NotificationBroadcast::whereKey($broadcast->id)
            ->whereNull('deadline_reminded_at')
            ->update(['deadline_reminded_at' => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        $deadline = CarbonImmutable::instance($broadcast->deadline_at);
        $fields = [
            'broadcast_id' => $broadcast->id,
            'title' => $broadcast->title,
            'deadline' => $deadline->setTimezone((string) config('notifications.default_timezone', 'Europe/Prague'))->format('j. n. Y H:i'),
            'hours_left' => max(1, (int) ceil($now->diffInMinutes($deadline, true) / 60)),
        ];

        $accepted = 0;
        $attempted = 0;

        NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->orderBy('id')
            ->chunkById(max(1, (int) config('notifications.broadcast.chunk', 500)), function (Collection $rows) use ($fields, &$accepted, &$attempted) {
                $attempted++;
                if (NotificationEvents::emit('broadcast.deadline_approaching', $fields, $rows->pluck('user_id')->all())) {
                    $accepted++;
                }
            });

        if ($attempted > 0 && $accepted === 0) {
            NotificationBroadcast::whereKey($broadcast->id)->update(['deadline_reminded_at' => null]);

            Log::channel('notifications')->warning('notifications.broadcast_deadline_retry', [
                'broadcast_id' => $broadcast->id,
            ]);

            return false;
        }

        return $attempted > 0;
    }
}
