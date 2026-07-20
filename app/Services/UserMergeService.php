<?php

namespace App\Services;

use App\Models\Bookmark;
use App\Models\ChatSession;
use App\Models\ContentFeedback;
use App\Models\DebugReport;
use App\Models\EloInteraction;
use App\Models\PracticeCard;
use App\Models\PracticeReviewLog;
use App\Models\QuizAttempt;
use App\Models\SkillEventLog;
use App\Models\StudentFsrsProfile;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserMerge;
use App\Models\UserProgress;
use App\Models\UserStats;
use App\Models\UserTheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * UserMergeService — combines two user profiles into one.
 *
 * Spec: docs/superpowers/specs/2026-05-03-user-merge-design.md
 *
 * Public API:
 *   - preview(int $sourceId, int $targetId): array
 *   - merge(int $sourceId, int $targetId, int $adminId): UserMerge
 *
 * Both methods enforce identical guards (§5.2). preview() reuses the merge
 * planner against in-memory copies and never writes to the DB. merge() runs
 * the entire flow inside a single DB::transaction with lockForUpdate() taken
 * on both users at the start.
 */
class UserMergeService
{
    // ═══════════════════════════════════════════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Plan a merge without touching the DB. Returns a diff structure
     * suitable for the admin UI's "Compare" pane.
     */
    public function preview(int $sourceId, int $targetId): array
    {
        [$source, $target] = $this->loadAndValidate($sourceId, $targetId, lock: false);

        // SPEC §5.2: server enforces direction for email-vs-PIN-only case;
        // if caller passed swapped ids the planner reports the corrected
        // pairing back to the UI.
        [$source, $target] = $this->enforceDirection($source, $target);

        return [
            'source' => $this->snapshotUser($source),
            'target' => $this->snapshotUser($target),
            'plan' => $this->planAll($source, $target),
        ];
    }

    /**
     * Execute the merge. All writes happen in one transaction.
     */
    public function merge(int $sourceId, int $targetId, int $adminId): UserMerge
    {
        return DB::transaction(function () use ($sourceId, $targetId, $adminId) {
            [$source, $target] = $this->loadAndValidate($sourceId, $targetId, lock: true);
            [$source, $target] = $this->enforceDirection($source, $target);

            $sourceSnapshot = $this->snapshotUser($source);
            $targetBefore = $this->snapshotUser($target);

            $log = [];

            $log['user_courses'] = $this->mergeUserCourses($source, $target);
            $log['user_progress'] = $this->mergeUserProgress($source, $target);
            $log['user_achievements'] = $this->mergeAchievements($source, $target);
            $log['bookmarks'] = $this->mergeBookmarks($source, $target);
            $log['user_themes'] = $this->mergeThemes($source, $target);
            $log['user_elo_profiles'] = $this->mergeEloProfiles($source, $target);
            $log['practice_cards'] = $this->mergePracticeCards($source, $target);
            $log['student_fsrs_profiles'] = $this->mergeFsrsProfile($source, $target);

            // Bulk reassignments — happen after dedupe-needing tables.
            $log['quiz_attempts'] = $this->reassignSimple($source, $target, QuizAttempt::class);
            $log['chat_sessions'] = $this->reassignSimple($source, $target, ChatSession::class);
            $log['elo_interactions'] = $this->reassignSimple($source, $target, EloInteraction::class);
            $log['practice_review_logs'] = $this->reassignSimple($source, $target, PracticeReviewLog::class);
            $log['content_feedback'] = $this->reassignSimple($source, $target, ContentFeedback::class);
            $log['debug_reports'] = $this->reassignSimple($source, $target, DebugReport::class);
            $log['skill_event_logs'] = $this->reassignSkillEventLogs($source, $target);

            // Stats merge depends on user_courses + achievements being merged first.
            $log['user_stats'] = $this->mergeUserStats($source, $target);

            $this->finalizeSource($source, $target);

            return UserMerge::create([
                'source_user_id' => $source->id,
                'target_user_id' => $target->id,
                'performed_by' => $adminId,
                'source_snapshot' => $sourceSnapshot,
                'target_before' => $targetBefore,
                'strategy_log' => $log,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Self-service guest → full-account merge, used by the guest-upgrade flows
     * (email verify / guest claim) when a guest signs into a pre-existing full
     * account. Unlike the admin merge there is no acting admin: authorisation
     * comes from the guest's own Sanctum token plus a recently-verified email,
     * and `performed_by` is recorded as the surviving target account.
     *
     * Returns null (without throwing) when the pairing is not a valid guest →
     * full merge — a null/non-guest source, self-merge, or a target that is
     * already merged or an admin/teacher — so callers can always fall through
     * to their normal login/error handling. Merge failures are reported but
     * never bubble up, so folding data in can never block a login.
     */
    public function absorbGuest(?User $guest, User $target): ?UserMerge
    {
        if ($guest === null || !$guest->is_guest || $guest->isMerged()) {
            return null;
        }
        if ($guest->id === $target->id) {
            return null;
        }
        if ($target->isMerged() || $target->isAdminOrTeacher()) {
            return null;
        }

        try {
            // performed_by = target: the account the guest is folding into is
            // the surviving owner, and there is no admin actor for self-service.
            return $this->merge($guest->id, $target->id, $target->id);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Validation & loading
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Load both users (optionally with row locks) and run §5.2 guards.
     *
     * @return array{0: User, 1: User}
     */
    private function loadAndValidate(int $sourceId, int $targetId, bool $lock): array
    {
        // Guard 1: same id
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages([
                'target_id' => ['Nelze sloučit uživatele se sebou samým.'],
            ]);
        }

        $sourceQuery = User::query();
        $targetQuery = User::query();

        if ($lock) {
            $sourceQuery->lockForUpdate();
            $targetQuery->lockForUpdate();
        }

        $source = $sourceQuery->find($sourceId);
        $target = $targetQuery->find($targetId);

        // Guard 2: both must exist
        if (!$source) {
            throw ValidationException::withMessages([
                'source_id' => ['Zdrojový uživatel nebyl nalezen.'],
            ]);
        }
        if (!$target) {
            throw ValidationException::withMessages([
                'target_id' => ['Cílový uživatel nebyl nalezen.'],
            ]);
        }

        // Guard 3: neither already merged
        if ($source->merged_into_user_id !== null) {
            throw ValidationException::withMessages([
                'source_id' => ['Zdrojový profil byl už sloučen do jiného účtu.'],
            ]);
        }
        if ($target->merged_into_user_id !== null) {
            throw ValidationException::withMessages([
                'target_id' => ['Cílový profil byl už sloučen do jiného účtu.'],
            ]);
        }

        // Guard 4: no admin/teacher accounts
        if ($source->isAdminOrTeacher() || $target->isAdminOrTeacher()) {
            throw ValidationException::withMessages([
                'role' => ['Nelze slučovat administrátorské nebo učitelské účty.'],
            ]);
        }

        return [$source, $target];
    }

    /**
     * SPEC §5.2 guard 5: when one user has email and the other only PIN,
     * server forces target = email user, ignoring caller's direction.
     *
     * @return array{0: User, 1: User} possibly swapped (source, target)
     */
    private function enforceDirection(User $source, User $target): array
    {
        $sourceHasEmail = !empty($source->email);
        $targetHasEmail = !empty($target->email);

        // Email vs PIN-only — email user MUST be the target.
        if ($sourceHasEmail && !$targetHasEmail) {
            // Caller picked email user as source; swap so email is target.
            return [$target, $source];
        }

        return [$source, $target];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Snapshot
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Capture a user's state for the audit table. Includes counts of
     * related rows so an auditor can reconstruct what the merge consumed.
     */
    private function snapshotUser(User $user): array
    {
        $stats = UserStats::where('user_id', $user->id)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'login_code' => $user->login_code,
            'role' => $user->role,
            'is_guest' => (bool) $user->is_guest,
            'device_id' => $user->device_id,
            'classroom_id' => $user->classroom_id,
            'avatar_index' => $user->avatar_index,
            'selected_subjects' => $user->selected_subjects,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'stats' => $stats ? [
                'level' => $stats->level,
                'xp_points' => $stats->xp_points,
                'streak_days' => $stats->streak_days,
                'last_streak_date' => $stats->last_streak_date?->toDateString(),
                'achievements_count' => $stats->achievements_count,
                'courses_count' => $stats->courses_count,
                'daily_xp_date' => $stats->daily_xp_date?->toDateString(),
                'daily_xp_amount' => $stats->daily_xp_amount,
            ] : null,
            'counts' => [
                'user_courses' => UserCourse::where('user_id', $user->id)->count(),
                'user_progress' => UserProgress::where('user_id', $user->id)->count(),
                'user_achievements' => UserAchievement::where('user_id', $user->id)->count(),
                'bookmarks' => Bookmark::where('user_id', $user->id)->count(),
                'quiz_attempts' => QuizAttempt::where('user_id', $user->id)->count(),
                'chat_sessions' => ChatSession::where('user_id', $user->id)->count(),
                'elo_interactions' => EloInteraction::where('user_id', $user->id)->count(),
                'practice_cards' => PracticeCard::where('user_id', $user->id)->count(),
                'practice_review_logs' => PracticeReviewLog::where('user_id', $user->id)->count(),
                'user_themes' => UserTheme::where('user_id', $user->id)->count(),
                'content_feedback' => ContentFeedback::where('user_id', $user->id)->count(),
                'debug_reports' => DebugReport::where('user_id', $user->id)->count(),
                'skill_event_logs' => SkillEventLog::where('student_id', $user->id)->count(),
            ],
        ];
    }

    /**
     * Build a non-destructive plan summary for preview(). Does not write.
     */
    private function planAll(User $source, User $target): array
    {
        return [
            'user_courses' => $this->planUserCourses($source, $target),
            'user_stats' => $this->planUserStats($source, $target),
            'counts' => [
                'user_progress' => UserProgress::where('user_id', $source->id)->count()
                    + UserProgress::where('user_id', $target->id)->count(),
                'user_achievements' => UserAchievement::where('user_id', $source->id)->count()
                    + UserAchievement::where('user_id', $target->id)->count(),
                'bookmarks' => Bookmark::where('user_id', $source->id)->count()
                    + Bookmark::where('user_id', $target->id)->count(),
                'quiz_attempts' => QuizAttempt::where('user_id', $source->id)->count()
                    + QuizAttempt::where('user_id', $target->id)->count(),
                'chat_sessions' => ChatSession::where('user_id', $source->id)->count()
                    + ChatSession::where('user_id', $target->id)->count(),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Per-table merge strategies (SPEC §6)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Per course_id: keep T's row but merge fields with S's. Sole-side rows reassigned.
     *   progress_percent = max(S, T)
     *   time_spent_seconds = S + T
     *   completed_lessons = max(S, T)
     *   current_lesson_index = winner of progress_percent
     *   progress_data = winner's
     *   started_at = min(S, T)
     *   completed_at = first non-null
     */
    private function mergeUserCourses(User $source, User $target): array
    {
        $stats = ['kept_target_only' => 0, 'reassigned_source_only' => 0, 'merged' => 0];

        $sourceCourses = UserCourse::where('user_id', $source->id)->get()->keyBy('course_id');
        $targetCourses = UserCourse::where('user_id', $target->id)->get()->keyBy('course_id');

        foreach ($sourceCourses as $courseId => $sCourse) {
            $tCourse = $targetCourses->get($courseId);

            if (!$tCourse) {
                // Only S has this course → reassign FK.
                $sCourse->user_id = $target->id;
                $sCourse->save();
                $stats['reassigned_source_only']++;
                continue;
            }

            // Both have a row → merge in-place onto target's row, drop source's.
            $sProgress = (int) $sCourse->progress_percent;
            $tProgress = (int) $tCourse->progress_percent;
            $progressWinner = $sProgress > $tProgress ? $sCourse : $tCourse;

            $tCourse->progress_percent = max($sProgress, $tProgress);
            $tCourse->time_spent_seconds = (int) $sCourse->time_spent_seconds + (int) $tCourse->time_spent_seconds;
            $tCourse->completed_lessons = max((int) $sCourse->completed_lessons, (int) $tCourse->completed_lessons);
            $tCourse->current_lesson_index = (int) $progressWinner->current_lesson_index;
            $tCourse->progress_data = $progressWinner->progress_data;

            // started_at: earliest non-null
            $startedCandidates = array_filter([$sCourse->started_at, $tCourse->started_at]);
            if (!empty($startedCandidates)) {
                $tCourse->started_at = min($startedCandidates);
            }

            // completed_at: first non-null (prefer target's existing value)
            $tCourse->completed_at = $tCourse->completed_at ?? $sCourse->completed_at;

            // status — promote to highest tier present.
            $tCourse->status = $this->bestCourseStatus($sCourse->status, $tCourse->status);

            $tCourse->save();
            $sCourse->delete();
            $stats['merged']++;
        }

        // Tally rows that target had with no source counterpart.
        foreach ($targetCourses as $courseId => $tCourse) {
            if (!$sourceCourses->has($courseId)) {
                $stats['kept_target_only']++;
            }
        }

        return $stats;
    }

    /** Pick the highest-tier course status. completed > in_progress > downloaded. */
    private function bestCourseStatus(?string $a, ?string $b): string
    {
        $rank = ['downloaded' => 0, 'in_progress' => 1, 'completed' => 2];
        $aRank = $rank[$a] ?? 0;
        $bRank = $rank[$b] ?? 0;
        return $aRank >= $bRank ? ($a ?? 'downloaded') : ($b ?? 'downloaded');
    }

    /**
     * Per lesson (user_id, course_id, lesson_id): keep row with higher
     * progress_percent (or is_completed=true over false). Tie → most recent.
     */
    private function mergeUserProgress(User $source, User $target): array
    {
        $stats = ['kept_target' => 0, 'kept_source' => 0, 'reassigned_source_only' => 0, 'kept_target_only' => 0];

        $sourceRows = UserProgress::where('user_id', $source->id)->get();
        $targetRows = UserProgress::where('user_id', $target->id)->get()
            ->keyBy(fn ($r) => $r->course_id . '|' . $r->lesson_id);

        foreach ($sourceRows as $sRow) {
            $key = $sRow->course_id . '|' . $sRow->lesson_id;
            $tRow = $targetRows->get($key);

            if (!$tRow) {
                $sRow->user_id = $target->id;
                $sRow->save();
                $stats['reassigned_source_only']++;
                continue;
            }

            // Pick winner.
            $sourceWins = false;
            if ((bool) $sRow->is_completed && !(bool) $tRow->is_completed) {
                $sourceWins = true;
            } elseif ((bool) $sRow->is_completed === (bool) $tRow->is_completed) {
                if ((int) $sRow->progress_percent > (int) $tRow->progress_percent) {
                    $sourceWins = true;
                } elseif ((int) $sRow->progress_percent === (int) $tRow->progress_percent) {
                    $sourceWins = $sRow->updated_at > $tRow->updated_at;
                }
            }

            if ($sourceWins) {
                // Replace target's row content with source's, then drop source's.
                $tRow->fill($sRow->only([
                    'progress_percent', 'is_completed', 'last_position',
                    'time_spent_seconds', 'progress_data', 'started_at', 'completed_at',
                ]));
                $tRow->save();
                $sRow->delete();
                $stats['kept_source']++;
            } else {
                $sRow->delete();
                $stats['kept_target']++;
            }
        }

        foreach ($targetRows as $key => $tRow) {
            $hasSource = $sourceRows->contains(fn ($r) => $r->course_id . '|' . $r->lesson_id === $key);
            if (!$hasSource) {
                $stats['kept_target_only']++;
            }
        }

        return $stats;
    }

    /** Union by achievement_id — dedupe collisions. */
    private function mergeAchievements(User $source, User $target): array
    {
        $stats = ['reassigned' => 0, 'duplicates_dropped' => 0];

        $targetIds = UserAchievement::where('user_id', $target->id)
            ->pluck('achievement_id')
            ->all();

        $sourceRows = UserAchievement::where('user_id', $source->id)->get();
        foreach ($sourceRows as $row) {
            if (in_array($row->achievement_id, $targetIds, true)) {
                $row->delete();
                $stats['duplicates_dropped']++;
            } else {
                $row->user_id = $target->id;
                $row->save();
                $stats['reassigned']++;
            }
        }

        return $stats;
    }

    /** Union by (course_id, lesson_id, block_id). */
    private function mergeBookmarks(User $source, User $target): array
    {
        $stats = ['reassigned' => 0, 'duplicates_dropped' => 0];

        $targetKeys = Bookmark::where('user_id', $target->id)
            ->get(['course_id', 'lesson_id', 'block_id'])
            ->map(fn ($b) => $b->course_id . '|' . $b->lesson_id . '|' . $b->block_id)
            ->all();

        $sourceRows = Bookmark::where('user_id', $source->id)->get();
        foreach ($sourceRows as $row) {
            $key = $row->course_id . '|' . $row->lesson_id . '|' . $row->block_id;
            if (in_array($key, $targetKeys, true)) {
                $row->delete();
                $stats['duplicates_dropped']++;
            } else {
                $row->user_id = $target->id;
                $row->save();
                $stats['reassigned']++;
            }
        }

        return $stats;
    }

    /** Union by name. Sole-side rows reassigned. */
    private function mergeThemes(User $source, User $target): array
    {
        $stats = ['reassigned' => 0, 'duplicates_dropped' => 0];

        $targetNames = UserTheme::where('user_id', $target->id)
            ->pluck('name')
            ->all();

        $sourceRows = UserTheme::where('user_id', $source->id)->get();
        foreach ($sourceRows as $row) {
            if (in_array($row->name, $targetNames, true)) {
                $row->delete();
                $stats['duplicates_dropped']++;
            } else {
                // SPEC: only one theme can be active per user — clear active flag
                // when reassigning so we don't accidentally end up with 2 active.
                if ($row->is_active) {
                    $hasActiveTarget = UserTheme::where('user_id', $target->id)
                        ->where('is_active', true)
                        ->exists();
                    if ($hasActiveTarget) {
                        $row->is_active = false;
                    }
                }
                $row->user_id = $target->id;
                $row->save();
                $stats['reassigned']++;
            }
        }

        return $stats;
    }

    /**
     * Per skill: rating = max, games_played = sum, wins = sum, losses = sum.
     * The schema in this codebase only has one row per user (unique user_id),
     * with profil_elo / profil_pocet as 35-element arrays — so we merge those
     * arrays element-wise: ELO = max per dim, pocet = sum per dim.
     */
    private function mergeEloProfiles(User $source, User $target): array
    {
        $sourceProfile = UserEloProfile::where('user_id', $source->id)->first();
        $targetProfile = UserEloProfile::where('user_id', $target->id)->first();

        if (!$sourceProfile && !$targetProfile) {
            return ['action' => 'none'];
        }

        if ($sourceProfile && !$targetProfile) {
            $sourceProfile->user_id = $target->id;
            $sourceProfile->save();
            return ['action' => 'reassigned_source'];
        }

        if (!$sourceProfile && $targetProfile) {
            return ['action' => 'kept_target'];
        }

        // Both exist → element-wise merge.
        $sElo = (array) ($sourceProfile->profil_elo ?? []);
        $tElo = (array) ($targetProfile->profil_elo ?? []);
        $sPocet = (array) ($sourceProfile->profil_pocet ?? []);
        $tPocet = (array) ($targetProfile->profil_pocet ?? []);

        $len = max(count($sElo), count($tElo), count($sPocet), count($tPocet));

        $mergedElo = [];
        $mergedPocet = [];
        for ($i = 0; $i < $len; $i++) {
            // SPEC: rating = max per skill dimension
            $sv = $sElo[$i] ?? null;
            $tv = $tElo[$i] ?? null;
            if ($sv === null && $tv === null) {
                $mergedElo[$i] = null;
            } elseif ($sv === null) {
                $mergedElo[$i] = $tv;
            } elseif ($tv === null) {
                $mergedElo[$i] = $sv;
            } else {
                $mergedElo[$i] = max($sv, $tv);
            }

            $mergedPocet[$i] = (int) ($sPocet[$i] ?? 0) + (int) ($tPocet[$i] ?? 0);
        }

        $targetProfile->profil_elo = $mergedElo;
        $targetProfile->profil_pocet = $mergedPocet;
        $targetProfile->save();
        $sourceProfile->delete();

        return ['action' => 'merged', 'dims' => $len];
    }

    /**
     * Per (user_id, block_id): keep the card with higher reps; tie → later due_date.
     * Sole-side rows reassigned.
     */
    private function mergePracticeCards(User $source, User $target): array
    {
        $stats = ['kept_target' => 0, 'kept_source' => 0, 'reassigned_source_only' => 0];

        $sourceCards = PracticeCard::where('user_id', $source->id)->get();
        $targetCards = PracticeCard::where('user_id', $target->id)->get()
            ->keyBy('block_id');

        foreach ($sourceCards as $sCard) {
            $tCard = $targetCards->get($sCard->block_id);

            if (!$tCard) {
                $sCard->user_id = $target->id;
                $sCard->save();
                $stats['reassigned_source_only']++;
                continue;
            }

            $sourceWins = false;
            if ((int) $sCard->reps > (int) $tCard->reps) {
                $sourceWins = true;
            } elseif ((int) $sCard->reps === (int) $tCard->reps) {
                $sourceWins = ($sCard->due_date ?? now()) > ($tCard->due_date ?? now());
            }

            if ($sourceWins) {
                // Reassign source's review logs to point at target's card_id
                // before swapping rows — preserves history continuity.
                PracticeReviewLog::where('card_id', $sCard->id)
                    ->update(['card_id' => $tCard->id]);

                $tCard->fill($sCard->only([
                    'state', 'due_date', 'stability', 'difficulty', 'reps', 'lapses',
                    'scheduled_days', 'elapsed_days', 'last_review', 'weight',
                    'avg_time_sec', 'skip_condition', 'is_active', 'course_id',
                    'lesson_id', 'source_type',
                ]));
                $tCard->save();
                $sCard->delete();
                $stats['kept_source']++;
            } else {
                // Reassign source's review logs onto target's card, then drop.
                PracticeReviewLog::where('card_id', $sCard->id)
                    ->update(['card_id' => $tCard->id]);
                $sCard->delete();
                $stats['kept_target']++;
            }
        }

        return $stats;
    }

    /** Keep target's FSRS profile, delete source's. (per-user calibration, no merge). */
    private function mergeFsrsProfile(User $source, User $target): array
    {
        $deleted = StudentFsrsProfile::where('user_id', $source->id)->delete();
        return ['source_deleted' => $deleted, 'target_kept_user_id' => $target->id];
    }

    /**
     * Generic FK reassignment for tables where the only operation is
     * UPDATE user_id = T WHERE user_id = S.
     */
    private function reassignSimple(User $source, User $target, string $modelClass): array
    {
        /** @var \Illuminate\Database\Eloquent\Model $modelClass */
        $count = $modelClass::where('user_id', $source->id)
            ->update(['user_id' => $target->id]);

        return ['reassigned' => $count];
    }

    /** SkillEventLog uses student_id, not user_id. */
    private function reassignSkillEventLogs(User $source, User $target): array
    {
        $count = SkillEventLog::where('student_id', $source->id)
            ->update(['student_id' => $target->id]);

        return ['reassigned' => $count];
    }

    /**
     * Sum XP, max streak, recompute level from XP, recompute counts.
     */
    private function mergeUserStats(User $source, User $target): array
    {
        $sourceStats = UserStats::where('user_id', $source->id)->first();
        $targetStats = UserStats::getOrCreateForUser($target->id);

        if (!$sourceStats) {
            // Recompute target's counts only.
            $targetStats->courses_count = UserCourse::where('user_id', $target->id)->count();
            $targetStats->achievements_count = UserAchievement::where('user_id', $target->id)->count();
            $targetStats->save();

            return [
                'xp_points_after' => $targetStats->xp_points,
                'streak_days_after' => $targetStats->streak_days,
                'level_after' => $targetStats->level,
            ];
        }

        $totalXp = (int) $sourceStats->xp_points + (int) $targetStats->xp_points;
        $targetStats->xp_points = $totalXp;
        // SPEC: recompute level from XP via existing formula. No formula was
        // found in the codebase for level — using simple floor(xp/1000) + 1.
        // TODO: replace with canonical level formula once defined.
        $targetStats->level = $this->levelFromXp($totalXp);

        $targetStats->streak_days = max((int) $sourceStats->streak_days, (int) $targetStats->streak_days);

        // last_streak_date = max
        $sLast = $sourceStats->last_streak_date;
        $tLast = $targetStats->last_streak_date;
        if ($sLast && (!$tLast || $sLast > $tLast)) {
            $targetStats->last_streak_date = $sLast;
        }

        // Daily XP merge: if same date → sum; else keep target's existing values.
        $sDaily = $sourceStats->daily_xp_date;
        $tDaily = $targetStats->daily_xp_date;
        if ($sDaily && $tDaily && $sDaily->toDateString() === $tDaily->toDateString()) {
            $targetStats->daily_xp_amount = (int) $sourceStats->daily_xp_amount + (int) $targetStats->daily_xp_amount;
        }

        // Recompute counts post-merge.
        $targetStats->courses_count = UserCourse::where('user_id', $target->id)->count();
        $targetStats->achievements_count = UserAchievement::where('user_id', $target->id)->count();

        $targetStats->save();
        $sourceStats->delete();

        return [
            'xp_points_after' => $targetStats->xp_points,
            'streak_days_after' => $targetStats->streak_days,
            'level_after' => $targetStats->level,
            'courses_count_after' => $targetStats->courses_count,
            'achievements_count_after' => $targetStats->achievements_count,
        ];
    }

    /**
     * SPEC: simple fallback formula until the canonical one is defined.
     * floor(xp / 1000) + 1.
     */
    private function levelFromXp(int $xp): int
    {
        return (int) floor(max(0, $xp) / 1000) + 1;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Finalize source
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Null out source.email, set merge pointer & timestamp. login_code (PIN)
     * is intentionally preserved (burned, never reused) per spec decision #2.
     */
    private function finalizeSource(User $source, User $target): void
    {
        $source->email = null;
        $source->merged_into_user_id = $target->id;
        $source->merged_at = now();
        $source->save();

        // Revoke any active tokens so a stale Sanctum token cannot be used.
        $source->tokens()->delete();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Preview helpers (read-only planning)
    // ═══════════════════════════════════════════════════════════════════════════

    private function planUserCourses(User $source, User $target): array
    {
        $sourceCourses = UserCourse::where('user_id', $source->id)->get()->keyBy('course_id');
        $targetCourses = UserCourse::where('user_id', $target->id)->get()->keyBy('course_id');

        $rows = [];
        $allCourseIds = $sourceCourses->keys()->merge($targetCourses->keys())->unique();

        foreach ($allCourseIds as $courseId) {
            $s = $sourceCourses->get($courseId);
            $t = $targetCourses->get($courseId);
            $rows[] = [
                'course_id' => $courseId,
                'source_progress' => $s?->progress_percent,
                'target_progress' => $t?->progress_percent,
                'merged_progress' => max((int) ($s?->progress_percent ?? 0), (int) ($t?->progress_percent ?? 0)),
                'merged_time_spent' => (int) ($s?->time_spent_seconds ?? 0) + (int) ($t?->time_spent_seconds ?? 0),
            ];
        }

        return $rows;
    }

    private function planUserStats(User $source, User $target): array
    {
        $s = UserStats::where('user_id', $source->id)->first();
        $t = UserStats::where('user_id', $target->id)->first();

        $totalXp = (int) ($s?->xp_points ?? 0) + (int) ($t?->xp_points ?? 0);

        return [
            'xp_after' => $totalXp,
            'level_after' => $this->levelFromXp($totalXp),
            'streak_after' => max((int) ($s?->streak_days ?? 0), (int) ($t?->streak_days ?? 0)),
        ];
    }
}
