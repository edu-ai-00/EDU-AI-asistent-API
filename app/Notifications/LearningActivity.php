<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Has this student learned today?" for the scheduled notification
 * commands (spec §3.3 habit.reminder_due, §3.5 activation).
 *
 * Learning = a signal the app only sends while the student actually works:
 * a work-time heartbeat (written only on learning screens with real
 * interaction), a quiz attempt, a practice review or a mini game play.
 * Merely opening the app (personal_access_tokens.last_used_at — the app
 * polls the badge every minute in the foreground) is "used the app", which
 * the commands use separately for the 14-day / inactivity windows.
 *
 * Everything works on whole chunks of user ids: one query per source, never
 * one per user.
 */
class LearningActivity
{
    /**
     * Ids from $userIds with any learning signal at or after $since.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, true>  user_id => true
     */
    public static function activeSince(array $userIds, CarbonInterface $since): array
    {
        if ($userIds === []) {
            return [];
        }

        $since = CarbonImmutable::instance($since)->utc();
        $active = [];

        $sources = [
            ['work_heartbeats', 'occurred_at'],
            ['quiz_attempts', 'created_at'],
            ['practice_review_logs', 'reviewed_at'],
            ['mini_game_plays', 'played_at'],
        ];

        foreach ($sources as [$table, $column]) {
            $remaining = array_values(array_diff($userIds, array_keys($active)));
            if ($remaining === []) {
                break;
            }

            $ids = DB::table($table)
                ->whereIn('user_id', $remaining)
                ->where($column, '>=', $since)
                ->distinct()
                ->pluck('user_id');

            foreach ($ids as $id) {
                $active[(int) $id] = true;
            }
        }

        return $active;
    }

    /**
     * The most recent learning signal per user (any source), UTC.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public static function lastActivity(array $userIds): array
    {
        $latest = [];

        foreach ([
            self::latestBy('work_heartbeats', 'occurred_at', $userIds),
            self::latestBy('quiz_attempts', 'created_at', $userIds),
            self::latestBy('practice_review_logs', 'reviewed_at', $userIds),
            self::latestBy('mini_game_plays', 'played_at', $userIds),
        ] as $source) {
            foreach ($source as $userId => $at) {
                if (!isset($latest[$userId]) || $at->greaterThan($latest[$userId])) {
                    $latest[$userId] = $at;
                }
            }
        }

        return $latest;
    }

    /**
     * MAX($column) per user from one table, UTC.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public static function latestBy(string $table, string $column, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw("user_id, MAX({$column}) as latest")
            ->get()
            ->filter(fn ($row) => $row->latest !== null)
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => CarbonImmutable::parse($row->latest, 'UTC')->utc()])
            ->all();
    }

    /**
     * Each user's effective timezone (their preference, else the default).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    public static function timezones(array $userIds): array
    {
        $stored = $userIds === []
            ? collect()
            : NotificationPreference::whereIn('user_id', $userIds)->pluck('timezone', 'user_id');

        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = QuietHours::safeTimezone($stored[$id] ?? null);
        }

        return $out;
    }

    /**
     * Students who can receive automatic notifications at all: role student,
     * not deactivated, not merged away.
     */
    public static function eligibleStudents()
    {
        return User::query()
            ->where('users.role', 'student')
            ->whereNull('users.deactivated_at')
            ->whereNull('users.merged_into_user_id');
    }

    /**
     * Whole local calendar days between two moments, in $timezone
     * (Monday 23:00 → Wednesday 08:00 is 2).
     */
    public static function localDaysBetween(CarbonInterface $earlier, CarbonInterface $later, string $timezone): int
    {
        $from = CarbonImmutable::instance($earlier)->setTimezone($timezone)->startOfDay();
        $to = CarbonImmutable::instance($later)->setTimezone($timezone)->startOfDay();

        return (int) round($from->diffInDays($to, false));
    }
}
