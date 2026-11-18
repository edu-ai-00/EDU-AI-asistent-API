<?php

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Notifications\LearningActivity;
use App\Notifications\NotificationEvents;
use App\Notifications\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * habit.reminder_due (spec §3.3, §6.2) — every 5 minutes.
 *
 * A student whose chosen reminder_time (notification_preferences, opt-in)
 * fell within the last five minutes of their own local time, and who has not
 * learned yet that local day, gets the event. The window is whole minutes,
 * (now − 5, now], so runs every five minutes cover each minute exactly once;
 * a repeated or overlapping run is harmless — the dedup key is the
 * student's local date.
 */
class SendLearningReminders extends Command
{
    protected $signature = 'notifications:learning-reminders';

    protected $description = 'Emit habit.reminder_due for students whose learning reminder time just came';

    private const WINDOW_MINUTES = 5;

    private const CHUNK = 500;

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $emitted = 0;

        NotificationPreference::query()
            ->whereNotNull('reminder_time')
            ->whereIn('user_id', LearningActivity::eligibleStudents()->select('users.id'))
            ->chunkById(self::CHUNK, function (Collection $preferences) use ($now, &$emitted) {
                $emitted += $this->processChunk($preferences, $now);
            }, 'user_id');

        $this->info("Learning reminders: {$emitted} student(s) reminded.");

        return self::SUCCESS;
    }

    /** @param  Collection<int, NotificationPreference>  $preferences */
    private function processChunk(Collection $preferences, CarbonImmutable $now): int
    {
        // tz|local date|HH:MM => [user ids]
        $due = [];

        foreach ($preferences as $preference) {
            $timezone = QuietHours::safeTimezone($preference->timezone);
            $local = $now->setTimezone($timezone);
            $reminder = NotificationPreference::hhmm($preference->reminder_time);
            $behind = $reminder === null ? null : self::minutesSince($reminder, $local);

            if ($behind === null || $behind >= self::WINDOW_MINUTES) {
                continue;
            }

            // The reminder's own local day: 23:58 caught by the 00:00 run is
            // still yesterday's reminder.
            $day = $local->subMinutes($behind)->toDateString();
            $due[$timezone . '|' . $day . '|' . $reminder][] = (int) $preference->user_id;
        }

        $emitted = 0;

        foreach ($due as $key => $userIds) {
            [$timezone, $date, $reminder] = explode('|', $key);

            $dayStart = CarbonImmutable::parse($date, $timezone)->startOfDay();
            $active = LearningActivity::activeSince($userIds, $dayStart);
            $idle = array_values(array_filter($userIds, fn (int $id) => !isset($active[$id])));

            if ($idle === []) {
                continue;
            }

            if (NotificationEvents::emit('habit.reminder_due', ['local_time' => $reminder], $idle, 'learning_reminder:' . $date)) {
                $emitted += count($idle);
            }
        }

        return $emitted;
    }

    /**
     * Whole minutes since the HH:MM reminder last came on the local clock
     * (0 = this minute), wrapping over midnight. Due when < WINDOW_MINUTES,
     * i.e. the reminder lies in (local − 5 min, local].
     */
    public static function minutesSince(string $reminder, CarbonImmutable $local): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $reminder) + [1 => 0]);
        $reminderMinute = ($hour % 24) * 60 + ($minute % 60);
        $nowMinute = $local->hour * 60 + $local->minute;

        return ($nowMinute - $reminderMinute + 1440) % 1440;
    }
}
