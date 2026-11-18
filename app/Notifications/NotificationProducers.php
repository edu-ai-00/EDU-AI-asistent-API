<?php

namespace App\Notifications;

use App\Models\Course;
use App\Models\NotificationUserState;
use App\Models\SkillVector;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserStats;
use App\Models\WorkHeartbeat;
use App\Support\WorkSessionizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The domain-event producers that live in request handlers (spec §3.3, §6.4).
 *
 * Each hook compares a value before and after the request's own write and
 * emits an event only on the crossing — "streak reached 7", "course went
 * past 50 %" — so a client re-syncing the same numbers emits nothing.
 *
 * Every hook runs through safely(): whatever goes wrong in here is logged on
 * the `notifications` channel and swallowed. A notification must never cost
 * the student their sync. The work itself is a few indexed reads at most;
 * rule evaluation and sending happen on the queue (NotificationEvents::emit).
 *
 * Events are only produced for students — every automatic type targets
 * them, and anything else would just log role_mismatch rejections.
 */
class NotificationProducers
{
    public const STREAK_MILESTONES = [7, 30, 60, 100];

    public const COURSE_MILESTONE_PERCENT = 50;

    /** How far back the current study session is looked for. */
    private const SESSION_LOOKBACK_HOURS = 12;

    /**
     * Run a producer so nothing it does can break or fail the request that
     * triggered it.
     */
    public static function safely(string $hook, int $userId, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                Log::channel('notifications')->error('notifications.producer_failed', [
                    'hook' => $hook,
                    'user_id' => $userId,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            } catch (\Throwable) {
                // Even a broken log channel must not reach the request.
            }
        }
    }

    /** A target action (NOTIF-8 F), guarded like every other hook. */
    public static function action(int $userId, string $action): void
    {
        self::safely('target_action', $userId, fn () => NotificationTargetActions::record($userId, $action));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UserStatsController — streak.milestone, goal.completed (daily / weekly)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Snapshot of the stats fields the hooks compare, taken before the update.
     *
     * @return array{streak_days: int, xp_points: int, daily_xp_date: ?string, daily_xp_amount: int}
     */
    public static function statsSnapshot(UserStats $stats): array
    {
        return [
            'streak_days' => (int) $stats->streak_days,
            'xp_points' => (int) $stats->xp_points,
            'daily_xp_date' => $stats->daily_xp_date?->toDateString(),
            'daily_xp_amount' => (int) ($stats->daily_xp_amount ?? 0),
        ];
    }

    /**
     * @param  array{streak_days: int, xp_points: int, daily_xp_date: ?string, daily_xp_amount: int}  $before
     */
    public static function statsSynced(User $user, array $before, UserStats $after): void
    {
        self::safely('stats', $user->id, function () use ($user, $before, $after) {
            $now = self::statsSnapshot($after);
            $xpGrew = $now['xp_points'] > $before['xp_points'];

            if ($xpGrew) {
                NotificationTargetActions::record($user->id, NotificationTargetActions::LEARNING_ACTIVITY);
            }

            if (!self::isStudent($user)) {
                return;
            }

            self::streakMilestone($user->id, $before['streak_days'], $now['streak_days']);
            self::dailyGoal($user->id, $before, $now);

            if ($xpGrew) {
                self::weeklyGoal($user->id, $before['xp_points'], $now['xp_points']);
            }
        });
    }

    /** One event for the highest milestone the streak went past (5 → 8 is "7"). */
    private static function streakMilestone(int $userId, int $before, int $after): void
    {
        $crossed = array_filter(self::STREAK_MILESTONES, fn (int $m) => $before < $m && $after >= $m);

        if ($crossed !== []) {
            NotificationEvents::emit('streak.milestone', ['streak_days' => max($crossed)], [$userId]);
        }
    }

    /**
     * The daily XP total went past the target on the same day, or a new day
     * started already past it. The day is the client's own daily_xp_date —
     * also the dedup key, so the goal counts once per that day.
     *
     * @param  array{daily_xp_date: ?string, daily_xp_amount: int}  $before
     * @param  array{daily_xp_date: ?string, daily_xp_amount: int}  $after
     */
    private static function dailyGoal(int $userId, array $before, array $after): void
    {
        $target = (int) config('notifications.goals.daily_xp', 50);
        $date = $after['daily_xp_date'];

        if ($target <= 0 || $date === null || $after['daily_xp_amount'] < $target) {
            return;
        }

        $crossed = $before['daily_xp_date'] === $date
            ? $before['daily_xp_amount'] < $target
            : true;

        // A late offline sync of an old day is history, not news. ±1 day
        // covers every client timezone.
        if (!$crossed || !self::isAroundToday($date)) {
            return;
        }

        NotificationEvents::emit('goal.completed', [
            'period' => 'daily',
            'xp' => $after['daily_xp_amount'],
            'target' => $target,
        ], [$userId], 'goal:daily:' . $date);
    }

    /**
     * Week XP = lifetime XP minus the baseline taken at the first XP growth
     * of the ISO week (default timezone). Emits when it goes past the target —
     * once, as XP never decreases server-side.
     */
    private static function weeklyGoal(int $userId, int $xpBefore, int $xpAfter): void
    {
        $target = (int) config('notifications.goals.weekly_xp', 250);
        if ($target <= 0) {
            return;
        }

        $week = CarbonImmutable::now((string) config('notifications.default_timezone', 'Europe/Prague'))->format('o-\WW');

        $state = NotificationUserState::find($userId) ?? new NotificationUserState(['user_id' => $userId]);
        if ($state->week_key !== $week || $state->week_start_xp === null) {
            $state->forceFill(['week_key' => $week, 'week_start_xp' => $xpBefore])->save();
        }

        $baseline = (int) $state->week_start_xp;
        $weekBefore = $xpBefore - $baseline;
        $weekAfter = $xpAfter - $baseline;

        if ($weekBefore < $target && $weekAfter >= $target) {
            NotificationEvents::emit('goal.completed', [
                'period' => 'weekly',
                'xp' => $weekAfter,
                'target' => $target,
            ], [$userId], 'goal:weekly:' . $week);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UserCourseController — course.progress_milestone, course.completed
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @param  array{progress_percent: int, status: ?string, completed_lessons: int}  $before
     */
    public static function courseProgressed(User $user, Course $course, array $before, UserCourse $after): void
    {
        self::safely('course', $user->id, function () use ($user, $course, $before, $after) {
            $percent = (int) $after->progress_percent;
            $completed = $before['status'] !== 'completed' && $after->status === 'completed';

            if ($percent > $before['progress_percent']
                || (int) $after->completed_lessons > $before['completed_lessons']
                || $completed) {
                NotificationTargetActions::record($user->id, NotificationTargetActions::LEARNING_ACTIVITY);
            }

            if (!self::isStudent($user)) {
                return;
            }

            $fields = ['course_id' => $course->id, 'course_name' => $course->name];
            if ($course->pin !== null && $course->pin !== '') {
                $fields['course_code'] = (string) $course->pin;
            }

            if ($completed) {
                NotificationEvents::emit('course.completed', $fields, [$user->id]);

                // Halfway and finished in one sync: the completion says it all.
                return;
            }

            $milestone = self::COURSE_MILESTONE_PERCENT;
            if ($before['progress_percent'] < $milestone && $percent >= $milestone) {
                NotificationEvents::emit('course.progress_milestone', $fields + ['percent' => $milestone], [$user->id]);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WorkHeartbeatController — study.long_session
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * The current study session — heartbeats walked back from the newest
     * while the gaps stay within the session gap, credited the way
     * WorkSessionizer credits work time — reaching long_session_minutes
     * emits study.long_session once for that session. A session whose
     * newest heartbeat is older than the gap is over (a late offline batch):
     * no break suggestion for it.
     */
    public static function heartbeatsReceived(User $user): void
    {
        self::safely('heartbeat', $user->id, function () use ($user) {
            NotificationTargetActions::record($user->id, NotificationTargetActions::LEARNING_ACTIVITY);

            if (!self::isStudent($user)) {
                return;
            }

            $threshold = (int) config('notifications.long_session_minutes', 45);
            if ($threshold <= 0) {
                return;
            }

            $session = self::currentSession($user->id);
            if ($session === null) {
                return;
            }

            $minutes = intdiv($session['duration'], 60);
            if ($minutes < $threshold) {
                return;
            }

            // Once per session; the service's dedup on session_started_at
            // backs this up should the cache be cleared.
            if (!Cache::add('notif:long_session:' . $user->id . ':' . $session['start'], true, now()->addHours(self::SESSION_LOOKBACK_HOURS))) {
                return;
            }

            NotificationEvents::emit('study.long_session', [
                'minutes' => $minutes,
                'session_started_at' => Carbon::createFromTimestampUTC($session['start'])->toIso8601String(),
            ], [$user->id]);
        });
    }

    /**
     * @return array{start: int, end: int, duration: int}|null  epoch seconds; duration in seconds
     */
    public static function currentSession(int $userId, ?\DateTimeInterface $now = null): ?array
    {
        $now = Carbon::instance($now ?? now());
        $gap = max(60, (int) config('notifications.session_gap_minutes', 30) * 60);

        $times = WorkHeartbeat::where('user_id', $userId)
            ->where('occurred_at', '>=', $now->copy()->subHours(self::SESSION_LOOKBACK_HOURS))
            ->where('occurred_at', '<=', $now->copy()->addMinutes(5))
            ->orderByDesc('occurred_at')
            ->limit(2000)
            ->pluck('occurred_at')
            ->map(fn ($t) => Carbon::parse($t)->getTimestamp());

        if ($times->isEmpty() || $times->first() < $now->getTimestamp() - $gap) {
            return null;
        }

        $session = [];
        $previous = null;
        foreach ($times as $ts) {
            if ($previous !== null && $previous - $ts > $gap) {
                break;
            }
            $session[] = $ts;
            $previous = $ts;
        }

        $sessions = (new WorkSessionizer($gap))->sessionize($session);

        return $sessions === [] ? null : end($sessions);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EloProfileController — topic.mastered
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * One topic.mastered per GPF dimension that went from not mastered to
     * mastered (rating ≥ mastery.elo with ≥ mastery.min_count answers).
     * Slot k is the dimension with dimension_index k of the canonical GPF
     * vector — the one /api/gpf/dimensions serves the app.
     *
     * @param  array<int|string, mixed>|null  $eloBefore
     * @param  array<int|string, mixed>|null  $countBefore
     * @param  array<int|string, mixed>|null  $eloAfter
     * @param  array<int|string, mixed>|null  $countAfter
     */
    public static function eloProfileUpdated(User $user, ?array $eloBefore, ?array $countBefore, ?array $eloAfter, ?array $countAfter): void
    {
        if ($eloBefore === $eloAfter && $countBefore === $countAfter) {
            return;
        }

        self::safely('elo_profile', $user->id, function () use ($user, $eloBefore, $countBefore, $eloAfter, $countAfter) {
            if (!self::isStudent($user)) {
                return;
            }

            $elo = (float) config('notifications.mastery.elo', 8.0);
            $minCount = (int) config('notifications.mastery.min_count', 10);

            $crossed = [];
            foreach (array_keys($eloAfter ?? []) as $slot) {
                if (!is_int($slot) && !(is_string($slot) && ctype_digit($slot))) {
                    continue;
                }

                $was = self::mastered($eloBefore[$slot] ?? null, $countBefore[$slot] ?? null, $elo, $minCount);
                $is = self::mastered($eloAfter[$slot] ?? null, $countAfter[$slot] ?? null, $elo, $minCount);

                if (!$was && $is) {
                    $crossed[] = (int) $slot;
                }
            }

            if ($crossed === []) {
                return;
            }

            $names = self::dimensionNames($crossed);

            foreach ($crossed as $slot) {
                $fields = ['topic_key' => 'dim:' . $slot];
                if (($names[$slot] ?? '') !== '') {
                    $fields['topic_name'] = $names[$slot];
                }

                NotificationEvents::emit('topic.mastered', $fields, [$user->id]);
            }
        });
    }

    private static function mastered(mixed $elo, mixed $count, float $threshold, int $minCount): bool
    {
        return is_numeric($elo) && is_numeric($count)
            && (float) $elo >= $threshold
            && (int) $count >= $minCount;
    }

    /**
     * @param  list<int>  $slots
     * @return array<int, string>  dimension_index => name
     */
    private static function dimensionNames(array $slots): array
    {
        $vector = SkillVector::public()->orderBy('created_at')->first();
        if ($vector === null) {
            return [];
        }

        return $vector->dimensions()
            ->whereIn('dimension_index', $slots)
            ->pluck('name', 'dimension_index')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private static function isStudent(User $user): bool
    {
        return $user->role === 'student' && !$user->isDeactivated() && !$user->isMerged();
    }

    private static function isAroundToday(string $date): bool
    {
        $today = CarbonImmutable::now((string) config('notifications.default_timezone', 'Europe/Prague'))->startOfDay();

        try {
            $day = CarbonImmutable::parse($date, $today->getTimezone())->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        return abs($today->diffInDays($day, false)) <= 1;
    }
}
