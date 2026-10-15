<?php

namespace App\Support;

use App\Models\EloInteraction;
use App\Models\MiniGamePlay;
use App\Models\NewsUserRead;
use App\Models\PracticeReviewLog;
use App\Models\User;
use App\Models\UserAvatarItem;
use App\Models\UserCourse;
use App\Models\UserStats;
use App\Models\WorkHeartbeat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-student, per-day activity overview (BR-AT7X2T).
 *
 * One row per (student, local calendar day) that saw any activity, assembled
 * from the event tables the app already fills — nothing here needs new
 * client logging. Days are bucketed in the reporting timezone
 * (config work.timezone) in PHP, like the work-time report, so the same code
 * runs on Postgres in prod and SQLite in tests.
 *
 * Sources per counter:
 *  - work_seconds      heartbeats + ELO interactions through WorkTimeReport
 *  - courses_visited   distinct course ids seen in any of the block sources
 *  - blocks_visited    distinct lesson blocks: user_courses.progress_data
 *                      block_timestamps ∪ non-quiz ELO interactions
 *  - practice_blocks   practice_review_logs (FSRS reviews)
 *  - quiz_blocks       distinct quiz-source ELO interactions
 *  - chats             distinct chat sessions the student wrote into
 *  - news_opened       news_user_reads
 *  - avatar_purchases  user_avatar_items — outfit changes have no history
 *                      (user_avatars is one row per user), so only purchases
 *                      are countable today
 *  - games             mini_game_plays
 *  - xp_points         user_stats.xp_points, the current total (no history)
 *
 * Known gaps the client would have to start logging: device/platform and
 * avatar outfit edits. Lesson block_timestamps keep only the first open per
 * block, so re-reading a text block on a later day is not a new visit.
 */
class ActivityReport
{
    /** Row keys in output order; the CSV export mirrors this. */
    public const COLUMNS = [
        'user_id',
        'user_name',
        'classroom_ids',
        'day',
        'work_seconds',
        'courses_visited',
        'blocks_visited',
        'practice_blocks',
        'quiz_blocks',
        'chats',
        'news_opened',
        'avatar_purchases',
        'games',
        'xp_points',
    ];

    private string $timezone;

    private ?string $from = null;

    private ?string $to = null;

    /** UTC query bounds, one day wider than [from, to] so edge sessions survive. */
    private ?Carbon $lower = null;

    private ?Carbon $upper = null;

    /** @var array<int, array<string, array<string, int>>> user_id => day => counters */
    private array $rows = [];

    /** @var array<int, array<string, array<string, array<string, true>>>> user_id => day => set name => members */
    private array $sets = [];

    public function __construct(private readonly WorkTimeReport $workTime)
    {
        $this->timezone = (string) config('work.timezone', 'Europe/Prague');
    }

    /**
     * @param  Collection<int, User>  $users  students to report on (id, name, classrooms loaded)
     * @param  string|null  $from  inclusive local day, Y-m-d
     * @param  string|null  $to  inclusive local day, Y-m-d
     * @return array<int, array<string, mixed>>
     */
    public function build(Collection $users, ?string $from, ?string $to): array
    {
        $userIds = $users->pluck('id')->all();
        if (empty($userIds)) {
            return [];
        }

        $this->from = $from;
        $this->to = $to;
        $this->lower = $from ? Carbon::parse($from, $this->timezone)->startOfDay()->subDay()->utc() : null;
        $this->upper = $to ? Carbon::parse($to, $this->timezone)->endOfDay()->addDay()->utc() : null;
        $this->rows = [];
        $this->sets = [];

        $this->collectWorkTimeAndBlocks($userIds);
        $this->collectLessonBlockTimestamps($userIds);
        $this->collectCount(PracticeReviewLog::query(), 'reviewed_at', $userIds, 'practice_blocks');
        $this->collectChats($userIds);
        $this->collectCount(NewsUserRead::query(), 'read_at', $userIds, 'news_opened');
        $this->collectCount(UserAvatarItem::query(), 'acquired_at', $userIds, 'avatar_purchases');
        $this->collectCount(MiniGamePlay::query(), 'played_at', $userIds, 'games');

        $xp = UserStats::query()->whereIn('user_id', $userIds)->pluck('xp_points', 'user_id');
        $byId = $users->keyBy('id');

        $out = [];
        ksort($this->rows);
        foreach ($this->rows as $userId => $days) {
            ksort($days);
            $user = $byId->get($userId);
            foreach ($days as $day => $counters) {
                $sets = $this->sets[$userId][$day] ?? [];
                $out[] = [
                    'user_id' => $userId,
                    'user_name' => $user?->name ?? '',
                    'classroom_ids' => $user ? $user->classrooms->pluck('id')->all() : [],
                    'day' => $day,
                    'work_seconds' => $counters['work_seconds'] ?? 0,
                    'courses_visited' => count($sets['courses'] ?? []),
                    'blocks_visited' => count($sets['blocks'] ?? []),
                    'practice_blocks' => $counters['practice_blocks'] ?? 0,
                    'quiz_blocks' => count($sets['quiz'] ?? []),
                    'chats' => count($sets['chats'] ?? []),
                    'news_opened' => $counters['news_opened'] ?? 0,
                    'avatar_purchases' => $counters['avatar_purchases'] ?? 0,
                    'games' => $counters['games'] ?? 0,
                    'xp_points' => (int) ($xp[$userId] ?? 0),
                ];
            }
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Collectors
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Heartbeats and ELO interactions feed three things at once: the work-time
     * sessionizer, the visited-course set, and the visited/quiz block sets.
     */
    private function collectWorkTimeAndBlocks(array $userIds): void
    {
        /** @var array<int, array<string, int[]>> $signals user_id => course_id => epoch seconds */
        $signals = [];

        $this->bounded(WorkHeartbeat::query()->whereIn('user_id', $userIds), 'occurred_at')
            ->orderBy('occurred_at')
            ->get(['user_id', 'course_id', 'occurred_at'])
            ->each(function (WorkHeartbeat $hb) use (&$signals) {
                $signals[$hb->user_id][$hb->course_id][] = $hb->occurred_at->getTimestamp();
                $this->addToSet($hb->user_id, $this->dayOf($hb->occurred_at), 'courses', $hb->course_id);
            });

        // ELO rows carry created_at only before BR-EQ9RVQ; prefer the
        // dedicated timestamps when present, like WorkTimeService does.
        EloInteraction::query()
            ->whereIn('user_id', $userIds)
            ->when($this->lower || $this->upper, fn (Builder $q) => $q->where(function (Builder $outer) {
                $outer->where(fn (Builder $w) => $this->bounded($w, 'confirmed_at'))
                    ->orWhere(fn (Builder $w) => $this->bounded($w->whereNull('confirmed_at'), 'created_at'));
            }))
            ->get(['user_id', 'course_id', 'block_id', 'source', 'opened_at', 'confirmed_at', 'created_at'])
            ->each(function (EloInteraction $it) use (&$signals) {
                $at = $it->confirmed_at ?? $it->opened_at ?? $it->created_at;
                if ($at === null) {
                    return;
                }
                $signals[$it->user_id][$it->course_id][] = $at->getTimestamp();

                $day = $this->dayOf($at);
                $this->addToSet($it->user_id, $day, 'courses', $it->course_id);
                $this->addToSet(
                    $it->user_id,
                    $day,
                    $it->source === 'quiz' ? 'quiz' : 'blocks',
                    $it->course_id.':'.$it->block_id,
                );
            });

        foreach ($signals as $userId => $byCourse) {
            $report = $this->workTime->aggregate($byCourse, $this->timezone);
            foreach ($report['by_day'] as $day => $seconds) {
                if ($this->inRange($day)) {
                    $this->row($userId, $day)['work_seconds'] += $seconds;
                }
            }
        }
    }

    /**
     * Text/lesson blocks never produce an ELO row; the app records them in
     * user_courses.progress_data.lessons[].block_timestamps (opened_at and
     * confirmed_at). JSON cannot be range-filtered in SQL, so every enrolment
     * of the selected students is scanned.
     */
    private function collectLessonBlockTimestamps(array $userIds): void
    {
        UserCourse::query()
            ->whereIn('user_id', $userIds)
            ->with('course:id,course_id')
            ->get(['id', 'user_id', 'course_id', 'progress_data'])
            ->each(function (UserCourse $uc) {
                $courseId = $uc->course?->course_id;
                $lessons = $uc->progress_data['lessons'] ?? null;
                if ($courseId === null || ! is_array($lessons)) {
                    return;
                }

                foreach ($lessons as $lesson) {
                    foreach ((array) ($lesson['block_timestamps'] ?? []) as $blockId => $ts) {
                        foreach (['opened_at', 'confirmed_at'] as $key) {
                            if (empty($ts[$key])) {
                                continue;
                            }
                            $day = $this->dayOf(Carbon::parse($ts[$key]));
                            $this->addToSet($uc->user_id, $day, 'courses', $courseId);
                            $this->addToSet($uc->user_id, $day, 'blocks', $courseId.':'.$blockId);
                        }
                    }
                }
            });
    }

    /**
     * A "chat" is a session the student wrote into that day — a conversation
     * continued across days counts once per day, and assistant replies do not
     * count on their own.
     */
    private function collectChats(array $userIds): void
    {
        $q = DB::table('chat_messages')
            ->join('chat_sessions', 'chat_sessions.id', '=', 'chat_messages.chat_session_id')
            ->where('chat_messages.role', 'user')
            ->whereIn('chat_sessions.user_id', $userIds);

        if ($this->lower) {
            $q->where('chat_messages.created_at', '>=', $this->lower);
        }
        if ($this->upper) {
            $q->where('chat_messages.created_at', '<=', $this->upper);
        }

        foreach ($q->get(['chat_sessions.user_id', 'chat_messages.chat_session_id', 'chat_messages.created_at']) as $m) {
            $this->addToSet(
                (int) $m->user_id,
                $this->dayOf(Carbon::parse($m->created_at)),
                'chats',
                (string) $m->chat_session_id,
            );
        }
    }

    /** Plain "one row = one event" tables: count rows per local day. */
    private function collectCount(Builder $query, string $column, array $userIds, string $counter): void
    {
        $this->bounded($query->whereIn('user_id', $userIds), $column)
            ->get(['user_id', $column])
            ->each(function ($row) use ($column, $counter) {
                $day = $this->dayOf($row->{$column});
                if ($this->inRange($day)) {
                    $this->row($row->user_id, $day)[$counter]++;
                }
            });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private function bounded(Builder $query, string $column): Builder
    {
        if ($this->lower) {
            $query->where($column, '>=', $this->lower);
        }
        if ($this->upper) {
            $query->where($column, '<=', $this->upper);
        }

        return $query;
    }

    private function dayOf(Carbon $at): string
    {
        return $at->copy()->setTimezone($this->timezone)->format('Y-m-d');
    }

    private function inRange(string $day): bool
    {
        return ($this->from === null || $day >= $this->from)
            && ($this->to === null || $day <= $this->to);
    }

    /** Counter bucket for (user, day), created on first touch. */
    private function &row(int $userId, string $day): array
    {
        if (! isset($this->rows[$userId][$day])) {
            $this->rows[$userId][$day] = [
                'work_seconds' => 0,
                'practice_blocks' => 0,
                'news_opened' => 0,
                'avatar_purchases' => 0,
                'games' => 0,
            ];
        }

        return $this->rows[$userId][$day];
    }

    /** Distinct-member sets (courses, blocks, quiz, chats) for (user, day). */
    private function addToSet(int $userId, string $day, string $set, string $member): void
    {
        if (! $this->inRange($day)) {
            return;
        }
        $this->row($userId, $day);
        $this->sets[$userId][$day][$set][$member] = true;
    }
}
