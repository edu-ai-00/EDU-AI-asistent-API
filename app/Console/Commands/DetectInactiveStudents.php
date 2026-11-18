<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\LearningActivity;
use App\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * user.inactive (spec §3.3, §6.2) — daily at 16:00.
 *
 * Inactivity is the number of whole local calendar days since the student's
 * last use of the app — MAX(personal_access_tokens.last_used_at) over their
 * sessions. At config('notifications.inactivity_days') or more the event is
 * emitted with days_inactive; how long is too long to still nudge is the
 * rule's call (the system rule stops at 30), as is how often (the type's
 * frequency limit). Students without any recorded use are left alone, and
 * so are students whose sessions are gone altogether (expired and pruned).
 * A second run the same day is deduplicated on inactive:{local date}.
 */
class DetectInactiveStudents extends Command
{
    protected $signature = 'notifications:inactivity';

    protected $description = 'Emit user.inactive for students who have not used the app for a while';

    private const CHUNK = 500;

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $threshold = max(1, (int) config('notifications.inactivity_days', 3));

        // Superset filter in SQL (UTC; two hours of slack for DST days); the
        // exact local-day count is below.
        $cutoff = $now->subDays($threshold - 1)->addHours(2);

        $lastUse = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereNotNull('last_used_at')
            ->groupBy('tokenable_id')
            ->selectRaw('tokenable_id, MAX(last_used_at) as last_used_at');

        $emitted = 0;

        LearningActivity::eligibleStudents()
            ->joinSub($lastUse, 'last_use', 'last_use.tokenable_id', '=', 'users.id')
            ->where('last_use.last_used_at', '<', $cutoff)
            ->select('users.id', 'last_use.last_used_at')
            ->chunkById(self::CHUNK, function (Collection $rows) use ($now, $threshold, &$emitted) {
                $emitted += $this->processChunk($rows, $now, $threshold);
            }, 'users.id', 'id');

        $this->info("Inactivity: {$emitted} student(s) reported inactive.");

        return self::SUCCESS;
    }

    private function processChunk(Collection $rows, CarbonImmutable $now, int $threshold): int
    {
        $timezones = LearningActivity::timezones($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        // days|local date => [user ids]
        $groups = [];

        foreach ($rows as $row) {
            $userId = (int) $row->id;
            $timezone = $timezones[$userId];
            $days = LearningActivity::localDaysBetween(CarbonImmutable::parse($row->last_used_at, 'UTC'), $now, $timezone);

            if ($days < $threshold) {
                continue;
            }

            $groups[$days . '|' . $now->setTimezone($timezone)->toDateString()][] = $userId;
        }

        $emitted = 0;

        foreach ($groups as $key => $userIds) {
            [$days, $date] = explode('|', $key);

            if (NotificationEvents::emit('user.inactive', ['days_inactive' => (int) $days], $userIds, 'inactive:' . $date)) {
                $emitted += count($userIds);
            }
        }

        return $emitted;
    }
}
