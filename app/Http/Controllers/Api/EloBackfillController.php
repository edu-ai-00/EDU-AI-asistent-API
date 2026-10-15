<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\QuizAttempt;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserProgress;
use App\Services\EloEngine;
use App\Services\TaskCalibrationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EloBackfillController extends Controller
{
    public function __construct(private TaskCalibrationService $calibration)
    {
    }

    /**
     * Pilot 2 gaming floor: a lesson answer submitted sooner than this after
     * the block was shown is a guess and moves no ELO. Mirrors
     * GamingGuard.minimumThresholdSeconds, the floor the app's lessons use
     * (gaming_filter.dart, BR-3N79C3).
     */
    private const GAMING_THRESHOLD_MS = 2000;

    /**
     * Preview backfill statistics for a course (dry run).
     *
     * GET /api/admin/elo/backfill/preview/{courseId}
     */
    public function preview(Request $request, string $courseId): JsonResponse
    {
        $course = Course::where('course_id', $courseId)->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $courseData = $course->data;
        if (! $courseData) {
            return response()->json(['message' => 'Course has no data JSON'], 422);
        }

        // Extract blocks with GPF vectors
        $blocks = $this->extractOrderedBlocks($courseData);
        $blocksWithGpf = array_filter($blocks, fn ($b) => $b['has_gpf']);
        $blockIds = array_map(fn ($b) => $b['block_id'], $blocks);

        // Find all users with progress on this course (lesson progress OR quiz attempts)
        $progressUserIds = DB::table('user_progress')->where('course_id', $courseId)->distinct()->pluck('user_id');
        $quizUserIds = DB::table('quiz_attempts')->where('course_id', $courseId)->distinct()->pluck('user_id');
        $userIds = $progressUserIds->merge($quizUserIds)->unique()->values();

        // Optional: filter to a specific user
        $filterUserId = $request->query('user_id');
        if ($filterUserId) {
            $userIds = $userIds->filter(fn ($id) => (int) $id === (int) $filterUserId)->values();
        }

        // Count existing interactions for this course
        $existingInteractions = EloInteraction::where('course_id', $courseId)->count();

        $lessonTimestamps = $this->loadLessonBlockTimestamps($course->id, $userIds->all());

        // Build per-user stats
        $users = [];
        foreach ($userIds as $userId) {
            $userProgress = UserProgress::where('course_id', $courseId)->where('user_id', $userId)->get();
            $userQuizAttempts = QuizAttempt::where('course_id', $courseId)->where('user_id', $userId)->get();
            $userQuizScores = $this->buildQuizScoreMap($userQuizAttempts);
            $completedBlocks = $this->getCompletedBlockIds($userProgress, $blockIds, $userQuizScores);

            $existingForUser = EloInteraction::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->pluck('block_id')
                ->toArray();

            $gpfBlockIds = array_map(fn ($b) => $b['block_id'], $blocksWithGpf);
            $pendingBlockIds = array_diff(
                array_intersect($completedBlocks, $gpfBlockIds),
                $existingForUser
            );
            // A guessed lesson answer never becomes an interaction (see
            // processUser), so it is not pending either — the admin's confirm
            // dialog promises exactly this count.
            $pendingBlockIds = array_filter(
                $pendingBlockIds,
                fn ($blockId) => isset($userQuizScores[$blockId])
                    || ! $this->isGamingLessonAttempt($lessonTimestamps[$userId.':'.$blockId] ?? null)
            );

            $user = DB::table('users')
                ->where('id', $userId)
                ->select('id', 'name')
                ->first();

            $users[] = [
                'id' => $userId,
                'name' => $user->name ?? "User #{$userId}",
                'blocks_attempted' => count($completedBlocks),
                'already_processed' => count(array_intersect($completedBlocks, $existingForUser)),
                'pending' => count($pendingBlockIds),
            ];
        }

        return response()->json([
            'data' => [
                'course_id' => $courseId,
                'course_name' => $course->name,
                'total_users_with_progress' => $userIds->count(),
                'blocks_with_gpf' => count($blocksWithGpf),
                'blocks_total' => count($blocks),
                'existing_interactions' => $existingInteractions,
                'pending_pairs' => array_sum(array_column($users, 'pending')),
                'users' => $users,
            ],
        ]);
    }

    /**
     * Run the actual ELO backfill for a course.
     *
     * Creates the missing interaction rows and student profiles from lesson
     * progress and quiz attempts. Task difficulty is no longer written here
     * (BR-697NCA): once the rows exist, every block that gained one is
     * recalibrated from all of its answers — `block_stats_updated` counts
     * those blocks.
     *
     * POST /api/admin/elo/backfill/run/{courseId}
     */
    public function run(Request $request, string $courseId): JsonResponse
    {
        $course = Course::where('course_id', $courseId)->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $courseData = $course->data;
        if (! $courseData) {
            return response()->json(['message' => 'Course has no data JSON'], 422);
        }

        // Extract blocks in lesson order
        $blocks = $this->extractOrderedBlocks($courseData);
        $blocksWithGpf = array_filter($blocks, fn ($b) => $b['has_gpf']);
        $blockIds = array_map(fn ($b) => $b['block_id'], $blocks);

        // Find all users with progress on this course (lesson progress OR quiz attempts)
        // Only pluck user_id to avoid loading full records into memory
        $progressUserIds = DB::table('user_progress')->where('course_id', $courseId)->distinct()->pluck('user_id');
        $quizUserIds = DB::table('quiz_attempts')->where('course_id', $courseId)->distinct()->pluck('user_id');
        $userIds = $progressUserIds->merge($quizUserIds)->unique()->values();

        // Optional: filter to a specific user
        $filterUserId = $request->query('user_id');
        if ($filterUserId) {
            $userIds = $userIds->filter(fn ($id) => (int) $id === (int) $filterUserId)->values();
        }

        $results = [
            'users_processed' => 0,
            'interactions_created' => 0,
            'profiles_updated' => 0,
            'block_stats_updated' => 0,
            'errors' => [],
            'details' => [],
        ];

        // Blocks that gained an interaction → recalibrated after the loop.
        $blockStatsUpdated = [];

        // The D the app would answer with right now (BR-697NCA): the
        // published calibration where there is one, the content elsewhere.
        $published = $this->calibration->publishedDifficulties(
            array_column($blocksWithGpf, 'block_id')
        );

        // opened_at / confirmed_at of every lesson block — what the gaming
        // rule reads, exactly as the app persisted it.
        $lessonTimestamps = $this->loadLessonBlockTimestamps($course->id, $userIds->all());

        foreach ($userIds as $userId) {
            try {
                // Load records per-user to avoid memory issues
                $userProgressRecords = UserProgress::where('course_id', $courseId)->where('user_id', $userId)->get();
                $userQuizAttempts = QuizAttempt::where('course_id', $courseId)->where('user_id', $userId)->get();

                $detail = DB::transaction(function () use (
                    $userId, $courseId, $blocksWithGpf, $blockIds,
                    $userProgressRecords, $userQuizAttempts, $lessonTimestamps, $published, &$blockStatsUpdated
                ) {
                    return $this->processUser(
                        $userId, $courseId, $blocksWithGpf, $blockIds,
                        $userProgressRecords, $userQuizAttempts, $lessonTimestamps, $published, $blockStatsUpdated
                    );
                });

                $results['users_processed']++;
                $results['interactions_created'] += $detail['interactions'];
                if ($detail['interactions'] > 0) {
                    $results['profiles_updated']++;
                }
                $results['details'][] = $detail;
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $gpfByBlock = TaskCalibrationService::extractBlockGpf($courseData);
        foreach (array_keys($blockStatsUpdated) as $blockId) {
            $gpf = $gpfByBlock[$blockId] ?? null;
            if ($gpf !== null) {
                $this->calibration->recalibrateBlock((string) $blockId, $gpf);
            }
        }
        $results['block_stats_updated'] = count($blockStatsUpdated);

        return response()->json(['data' => $results]);
    }

    /**
     * Process a single user's ELO backfill for a course.
     */
    private function processUser(
        int $userId,
        string $courseId,
        array $blocksWithGpf,
        array $allBlockIds,
        $progressRecords,
        $quizAttempts,
        array $lessonTimestamps,
        array $published,
        array &$blockStatsUpdated
    ): array {
        // Get or create UserEloProfile
        $profile = UserEloProfile::firstOrCreate(
            ['user_id' => $userId],
            [
                'profil_elo' => array_fill(0, EloEngine::GPF_DIMENSIONS, null),
                'profil_pocet' => array_fill(0, EloEngine::GPF_DIMENSIONS, 0),
            ]
        );

        $profilElo = $profile->profil_elo ?? array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $profilPocet = $profile->profil_pocet ?? array_fill(0, EloEngine::GPF_DIMENSIONS, 0);

        // Get existing interactions for this user + course → skip set
        $existingBlockIds = EloInteraction::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->pluck('block_id')
            ->flip()
            ->toArray();

        // Build a map of block progress from lesson progress records
        $blockProgress = $this->buildBlockProgressMap($progressRecords);

        // Build quiz answer map: blockId → is_correct
        $quizScores = $this->buildQuizScoreMap($quizAttempts);

        $interactionsCreated = 0;

        // Iterate blocks in lesson order
        foreach ($blocksWithGpf as $block) {
            $blockId = $block['block_id'];

            // Skip if already processed
            if (isset($existingBlockIds[$blockId])) {
                continue;
            }

            // Derive score
            $scoreResult = $this->deriveScore($blockId, $blockProgress, $quizScores);
            if ($scoreResult === null) {
                continue; // Block not completed
            }

            [$score, $source] = $scoreResult;

            // Pilot 2: a guess moves neither the ratings nor the counters. The
            // app skips it live, so the recompute must too, or a backfill
            // writes back the very ELO the client refused (BR-3N79C3). Quiz
            // guesses never reach here — buildQuizScoreMap drops them.
            if ($source === 'lesson'
                && $this->isGamingLessonAttempt($lessonTimestamps[$userId.':'.$blockId] ?? null)) {
                continue;
            }

            // Only the student half is used: D belongs to the calibration,
            // which replays this row once it exists (BR-697NCA).
            $taskElo = TaskCalibrationService::effective($block['elo_vector'], $published[$blockId] ?? null);
            $result = EloEngine::updateTask(
                $profilElo,
                $profilPocet,
                $block['relation_vector'],
                $taskElo,
                array_fill(0, EloEngine::GPF_DIMENSIONS, 0),
                $score
            );

            // Create EloInteraction record
            EloInteraction::create([
                'user_id' => $userId,
                'block_id' => $blockId,
                'course_id' => $block['course_id'],
                'source' => $source,
                'score' => $score,
                'profil_elo_before' => $profilElo,
                'profil_elo_snapshot' => $result['profil_elo'],
                'elo_vector_used' => $taskElo,
                // D before; the recalibration at the end of run() rewrites it to D after.
                'elo_vector_snapshot' => $taskElo,
                'updated_indices' => $result['updated_indices'],
                // Replayed with the PHP engine, so its configuration applies (BR-EBNMBF).
                'elo_config_version' => EloEngine::CONFIG_VERSION,
            ]);

            $blockStatsUpdated[$blockId] = true;

            // Carry forward updated profile for next block
            $profilElo = $result['profil_elo'];
            $profilPocet = $result['profil_pocet'];

            $interactionsCreated++;
        }

        // Save final profile
        if ($interactionsCreated > 0) {
            $profile->update([
                'profil_elo' => $profilElo,
                'profil_pocet' => $profilPocet,
            ]);
        }

        // Compute average ELO (non-null only)
        $nonNullElo = array_filter($profilElo, fn ($v) => $v !== null);
        $avgElo = count($nonNullElo) > 0
            ? round(array_sum($nonNullElo) / count($nonNullElo), 2)
            : null;

        $user = DB::table('users')->where('id', $userId)->select('name')->first();

        return [
            'user_id' => $userId,
            'user_name' => $user->name ?? "User #{$userId}",
            'interactions' => $interactionsCreated,
            'final_avg_elo' => $avgElo,
        ];
    }

    /**
     * Extract blocks from course data in lesson order, with GPF info.
     *
     * @return array<int, array{block_id: string, course_id: string, has_gpf: bool, relation_vector: array, elo_vector: array}>
     */
    private function extractOrderedBlocks(array $courseData): array
    {
        $blocks = [];
        $courseId = $courseData['course_id'] ?? '';

        // Build a lookup map from the top-level blocks array (where GPF data lives)
        $blockContentMap = [];
        foreach ($courseData['blocks'] ?? [] as $blockContent) {
            $id = $blockContent['block_id'] ?? $blockContent['id'] ?? null;
            if ($id) {
                $blockContentMap[$id] = $blockContent;
            }
        }

        $lessons = $courseData['lessons'] ?? [];
        // Sort lessons by order
        usort($lessons, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach ($lessons as $lesson) {
            $lessonBlocks = $lesson['blocks'] ?? [];
            // Sort blocks by order (binding order)
            usort($lessonBlocks, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            foreach ($lessonBlocks as $blockRef) {
                $blockId = $blockRef['block_id'] ?? $blockRef['id'] ?? null;
                if (! $blockId) {
                    continue;
                }

                // Look up the full block content (with GPF) from the top-level blocks array
                $blockContent = $blockContentMap[$blockId] ?? [];
                $gpf = $blockContent['gpf'] ?? $blockRef['gpf'] ?? null;
                $relationVector = $gpf['relation_vector'] ?? null;
                $eloVector = $gpf['elo_vector'] ?? null;

                $hasGpf = $relationVector !== null
                    && $eloVector !== null
                    && is_array($relationVector)
                    && is_array($eloVector);

                $blocks[] = [
                    'block_id' => $blockId,
                    'course_id' => $courseId,
                    'has_gpf' => $hasGpf,
                    'relation_vector' => $relationVector ?? [],
                    'elo_vector' => $eloVector ?? [],
                ];
            }
        }

        return $blocks;
    }

    /**
     * Build a map of block progress from user's lesson progress records.
     * Returns: blockId → { isCompleted, isCorrect, hintsUsed }
     */
    private function buildBlockProgressMap($userProgressRecords): array
    {
        $map = [];

        foreach ($userProgressRecords as $record) {
            $progressData = $record->progress_data ?? [];
            $blocksData = $progressData['blocks'] ?? [];

            foreach ($blocksData as $blockId => $blockData) {
                // Don't overwrite if we already have data (first record wins)
                if (! isset($map[$blockId])) {
                    $map[$blockId] = $blockData;
                }
            }
        }

        return $map;
    }

    /**
     * Build quiz score map from quiz attempts.
     * Returns: blockId → score (1.0 or 0.0)
     *
     * Answers the client's gaming filter flagged (`gamed`, BR-RQQVP6) are left
     * out: the quiz never moved ELO for them (BR-3N79C3).
     */
    private function buildQuizScoreMap($quizAttempts): array
    {
        $map = [];

        foreach ($quizAttempts as $attempt) {
            $answers = $attempt->answers ?? [];
            foreach ($answers as $answer) {
                if (! empty($answer['gamed'])) {
                    continue;
                }
                $questionId = $answer['question_id'] ?? $answer['block_id'] ?? null;
                if ($questionId && ! isset($map[$questionId])) {
                    $map[$questionId] = ! empty($answer['is_correct']) ? 1.0 : 0.0;
                }
            }
        }

        return $map;
    }

    /**
     * Derive score for a block from progress data and quiz data.
     *
     * @return array{0: float, 1: string}|null  [score, source] or null if not completed
     */
    private function deriveScore(string $blockId, array $blockProgress, array $quizScores): ?array
    {
        // Check quiz answers first (more precise)
        if (isset($quizScores[$blockId])) {
            return [$quizScores[$blockId], 'quiz'];
        }

        // Check lesson progress
        if (! isset($blockProgress[$blockId])) {
            return null;
        }

        $data = $blockProgress[$blockId];

        $isCompleted = ! empty($data['isCompleted']);
        if (! $isCompleted) {
            return null;
        }

        $isCorrect = ! empty($data['isCorrect']);
        if (! $isCorrect) {
            return [0.0, 'lesson'];
        }

        $hintsUsed = (int) ($data['hintsUsed'] ?? 0);
        if ($hintsUsed === 0) {
            return [1.0, 'lesson'];
        } elseif ($hintsUsed === 1) {
            return [0.75, 'lesson'];
        } else {
            return [0.5, 'lesson'];
        }
    }

    /**
     * Pilot 2 gaming rule for a lesson answer — same test as the app's
     * isGamingLessonAttempt (gaming_filter.dart), on the same pair the app
     * persisted as block_timestamps: opened_at (block shown) and confirmed_at
     * (answer submitted). Under GAMING_THRESHOLD_MS apart is a guess.
     *
     * Missing or unparsable timestamps give no verdict — never flag what
     * cannot be measured. The synthetic timings runTimestampBackfill writes
     * into elo_interactions are estimates, not measurements, and are never
     * read here.
     *
     * @param  array{opened_at?: mixed, confirmed_at?: mixed}|null  $timestamps
     */
    private function isGamingLessonAttempt(?array $timestamps): bool
    {
        $opened = $timestamps['opened_at'] ?? null;
        $confirmed = $timestamps['confirmed_at'] ?? null;
        if (! is_string($opened) || ! is_string($confirmed)) {
            return false;
        }

        try {
            $openedAt = Carbon::parse($opened);
            $confirmedAt = Carbon::parse($confirmed);
        } catch (\Throwable) {
            return false;
        }

        // Signed, like the app's Duration comparison: confirmed − opened.
        return $openedAt->diffInMilliseconds($confirmedAt, false) < self::GAMING_THRESHOLD_MS;
    }

    /**
     * Recalibrate task difficulty for the blocks of a course (BR-697NCA):
     * replay every answer to each block in answer order, from the course's
     * elo_vector and zero counts. See TaskCalibrationService::recalibrateBlock.
     *
     * POST /api/admin/elo/recalibrate/{courseId}?offset=&limit=&dry_run=
     *
     * Pages through the course's blocks like the timestamp backfill pages
     * through rows — Cloudflare cuts a request at ~120 s — so the caller
     * repeats with `offset = next_offset` until `done: true`. A dry run
     * computes the same numbers and rolls everything back.
     */
    public function recalibrate(Request $request, string $courseId): JsonResponse
    {
        @set_time_limit(300);

        $course = Course::where('course_id', $courseId)->first();
        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }
        if (! $course->data) {
            return response()->json(['message' => 'Course has no data JSON'], 422);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = max(1, min(500, (int) $request->query('limit', 50)));
        $dryRun = (bool) $request->query('dry_run', false);

        $result = $this->calibration->recalibrateCourse($course, $offset, $limit, $dryRun);

        return response()->json([
            'data' => [
                'course_id' => $courseId,
                'offset' => $offset,
                ...$result,
                'dry_run' => $dryRun,
            ],
        ]);
    }

    /**
     * Backfill opened_at / confirmed_at / duration_ms for historical
     * `elo_interactions` rows that pre-date client-side timestamp tracking.
     *
     * POST /api/admin/elo/backfill/timestamps/{courseId}
     *
     * Strategy per source:
     *   - `lesson` interactions → look up the matching
     *     user_courses.progress_data.lessons[].block_timestamps entry
     *     (the lesson UI has always tracked these).
     *   - `quiz` interactions → synthesise opened/confirmed from the row's
     *     created_at plus the gap to the previous quiz interaction in the
     *     same user+course session (≤ 30 min between rows). For the first
     *     interaction in a session use the median session gap, falling
     *     back to a conservative 25 s estimate.
     *
     * Synthetic durations are clamped to [3 s, 5 min] so the distribution
     * stays inside plausible answer-time bounds for grade-school items.
     */
    /**
     * Hard cap on how long a single block solving time can be in the export.
     * Real timestamps can span days (student left the lesson open and
     * resumed weeks later) but that's not useful as "doba řešení" and the
     * raw value used to overflow the old INTEGER column. Anything past
     * this cap is treated as an abandoned attempt and clamped down.
     */
    private const MAX_REASONABLE_DURATION_MS = 24 * 60 * 60 * 1000; // 24 h

    public function runTimestampBackfill(Request $request, string $courseId): JsonResponse
    {
        // The backfill issues one UPDATE per interaction. Even with chunked
        // commits, Cloudflare cuts the connection at ~120 s, so we also cap
        // how many rows a single request processes. Callers (admin UI / curl
        // loop) repeat the call until `done: true`.
        @set_time_limit(300);

        $course = Course::where('course_id', $courseId)->first();
        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $dryRun = (bool) $request->query('dry_run', false);
        // `force=1` also reprocesses rows that were filled in by a buggy
        // earlier run (rows currently at `duration_ms = 0` — e.g. the
        // Carbon 3 signed-diff bug). Rows with a positive `duration_ms`
        // are assumed to be real client-sent data and are NEVER touched,
        // regardless of the `force` flag, so a re-run can't clobber
        // legitimate timing from new app builds.
        $force = (bool) $request->query('force', false);
        // Max rows to process per HTTP request. Keeps each call well under
        // Cloudflare's 120 s timeout. Callers re-invoke until `done: true`.
        $limit = (int) $request->query('limit', 1500);
        $limit = max(1, min(5000, $limit));

        // Pull all interactions that still need timing data. We only need a
        // handful of columns — avoid Eloquent so the per-row overhead (casts,
        // event firing, dirty tracking) doesn't dominate the run.
        $query = DB::table('elo_interactions')
            ->select(['id', 'user_id', 'block_id', 'source', 'created_at'])
            ->where('course_id', $courseId);

        if ($force) {
            // Re-process every row that's either missing timing OR has the
            // tell-tale 0 from the buggy earlier run. Real positive
            // durations are left alone.
            $query->where(function ($q) {
                $q->whereNull('opened_at')
                    ->orWhereNull('confirmed_at')
                    ->orWhereNull('duration_ms')
                    ->orWhere('duration_ms', 0);
            });
        } else {
            $query->where(function ($q) {
                $q->whereNull('opened_at')
                    ->orWhereNull('confirmed_at')
                    ->orWhereNull('duration_ms');
            });
        }

        // Count how many rows still match the criteria. We slice the actual
        // work to `limit` so the HTTP call stays under Cloudflare's 120 s
        // proxy timeout; `total_remaining - processed` lets the caller
        // decide whether to invoke us again.
        $totalRemaining = (clone $query)->count();

        $interactions = $query
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($interactions->isEmpty()) {
            return response()->json([
                'data' => [
                    'course_id' => $courseId,
                    'pending' => 0,
                    'processed' => 0,
                    'total_remaining' => 0,
                    'lesson_filled' => 0,
                    'quiz_filled' => 0,
                    'unresolved' => 0,
                    'done' => true,
                    'dry_run' => $dryRun,
                ],
            ]);
        }

        // Normalise created_at strings → Carbon so the planner / fallback
        // logic can rely on Carbon helpers regardless of source.
        foreach ($interactions as $row) {
            $row->created_at = Carbon::parse($row->created_at);
        }

        // ── Lesson source — pull authoritative timestamps from user_courses. ──
        $userIds = $interactions->pluck('user_id')->unique()->values()->all();
        $lessonTimestamps = $this->loadLessonBlockTimestamps($course->id, $userIds);

        // ── Quiz source — synthesise from inter-row deltas per session. ──
        $quizPlans = $this->planQuizTimestamps($interactions);

        $lessonFilled = 0;
        $quizFilled = 0;
        $unresolved = 0;

        // Updates are issued one row at a time (each gets unique values) but
        // we commit in chunks so a stall on row N doesn't undo the previous
        // chunk's work — and Postgres doesn't have to hold one huge txn.
        $chunkSize = 500;
        $pendingUpdates = [];

        $flush = function () use (&$pendingUpdates, $dryRun): void {
            if ($dryRun || empty($pendingUpdates)) {
                $pendingUpdates = [];

                return;
            }
            DB::transaction(function () use ($pendingUpdates) {
                foreach ($pendingUpdates as $update) {
                    DB::table('elo_interactions')
                        ->where('id', $update['id'])
                        ->update([
                            'opened_at' => $update['opened_at'],
                            'confirmed_at' => $update['confirmed_at'],
                            'duration_ms' => $update['duration_ms'],
                            'updated_at' => now(),
                        ]);
                }
            });
            $pendingUpdates = [];
        };

        foreach ($interactions as $row) {
            $source = $row->source ?? 'lesson';

            if ($source === 'lesson') {
                $key = $row->user_id . ':' . $row->block_id;
                $ts = $lessonTimestamps[$key] ?? null;

                $opened = isset($ts['opened_at']) ? Carbon::parse($ts['opened_at']) : null;
                $confirmed = isset($ts['confirmed_at']) ? Carbon::parse($ts['confirmed_at']) : null;

                // Fall back: anchor confirmed_at on the interaction's
                // created_at and back-date opened_at by a synthetic gap
                // matching the user's quiz cadence (or 25 s default).
                if ($confirmed === null) {
                    $confirmed = $row->created_at->copy();
                }
                if ($opened === null) {
                    $opened = $confirmed->copy()->subSeconds(
                        $this->synthesisedGapSeconds($quizPlans)
                    );
                }
            } else {
                $plan = $quizPlans[$row->id] ?? null;
                if ($plan === null) {
                    $unresolved++;
                    continue;
                }
                $opened = $plan['opened'];
                $confirmed = $plan['confirmed'];
            }

            // Carbon 3 returns signed diffs ($a->diff($b) ≈ $b - $a). Wrap in
            // abs() so we always store a non-negative duration regardless of
            // which argument is later in time. Then clamp to a sane ceiling
            // (24 h) — real lesson timestamps can span days when a student
            // left the lesson open and resumed weeks later, which both
            // overflowed the old INTEGER column and produced nonsense for
            // the "doba řešení" cell. If we hit the cap, also back-date
            // opened_at so opened/confirmed stay internally consistent.
            $rawMs = (int) abs($confirmed->diffInMilliseconds($opened));
            if ($rawMs > self::MAX_REASONABLE_DURATION_MS) {
                $durationMs = self::MAX_REASONABLE_DURATION_MS;
                $opened = $confirmed->copy()
                    ->subMilliseconds(self::MAX_REASONABLE_DURATION_MS);
            } else {
                $durationMs = $rawMs;
            }

            $pendingUpdates[] = [
                'id' => $row->id,
                'opened_at' => $opened->toDateTimeString(),
                'confirmed_at' => $confirmed->toDateTimeString(),
                'duration_ms' => $durationMs,
            ];

            if (count($pendingUpdates) >= $chunkSize) {
                $flush();
            }

            if ($source === 'lesson') {
                $lessonFilled++;
            } else {
                $quizFilled++;
            }
        }

        $flush();

        $processed = $interactions->count();
        $remainingAfter = max(0, $totalRemaining - $processed);

        return response()->json([
            'data' => [
                'course_id' => $courseId,
                // `pending` retained for backwards compatibility — kept equal
                // to the count of rows we touched in this call.
                'pending' => $processed,
                'processed' => $processed,
                'total_remaining' => $remainingAfter,
                'lesson_filled' => $lessonFilled,
                'quiz_filled' => $quizFilled,
                'unresolved' => $unresolved,
                // True when no further calls are needed for this course.
                // Dry runs always report `done: true` since they don't
                // actually drain the pool.
                'done' => $dryRun ? true : $remainingAfter === 0,
                'dry_run' => $dryRun,
            ],
        ]);
    }

    /**
     * Load lesson block_timestamps for ($courseIntId, $userIds) from
     * user_courses.progress_data. Returns "{userId}:{blockId}" → {opened_at, confirmed_at}.
     */
    private function loadLessonBlockTimestamps(int $courseIntId, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $map = [];
        $userCourses = UserCourse::where('course_id', $courseIntId)
            ->whereIn('user_id', $userIds)
            ->get();

        foreach ($userCourses as $uc) {
            $progressData = $uc->progress_data;
            if (! is_array($progressData) || empty($progressData['lessons'])) {
                continue;
            }
            foreach ($progressData['lessons'] as $lessonData) {
                foreach ($lessonData['block_timestamps'] ?? [] as $blockId => $ts) {
                    $map[$uc->user_id . ':' . $blockId] = $ts;
                }
            }
        }

        return $map;
    }

    /**
     * Plan synthetic opened/confirmed timestamps for quiz interactions.
     *
     * For each user we walk their quiz interactions in chronological order.
     * Two adjacent rows ≤ 30 min apart belong to the same session. Each
     * row's `confirmed_at` is its `created_at`; `opened_at` is the prior
     * row's `created_at` in the same session. The first row in a session
     * gets `opened_at = created_at - synthesisedGapSeconds`.
     *
     * Per-row deltas are clamped to [3 s, 5 min] so synthesised durations
     * stay within plausible answer-time bounds.
     *
     * @return array<int, array{opened: Carbon, confirmed: Carbon}>
     *         keyed by EloInteraction id.
     */
    private function planQuizTimestamps($interactions): array
    {
        $plan = [];

        // Group quiz rows by user, preserving chronological order from the
        // outer ::get() query.
        $byUser = [];
        foreach ($interactions as $i) {
            if (($i->source ?? 'lesson') !== 'quiz') {
                continue;
            }
            $byUser[$i->user_id][] = $i;
        }

        foreach ($byUser as $rows) {
            // Collect inter-row gaps for this user to derive a personalised
            // baseline. Sessions are bounded by a 30-min idle gap.
            // abs() guards against Carbon 3's signed diff semantics.
            $sessionGaps = [];
            for ($idx = 1; $idx < count($rows); $idx++) {
                $gap = (int) abs($rows[$idx]->created_at->diffInSeconds($rows[$idx - 1]->created_at));
                if ($gap > 0 && $gap <= 30 * 60) {
                    $sessionGaps[] = $gap;
                }
            }
            $baseline = $this->medianClamped($sessionGaps, 25, 3, 300);

            $prev = null;
            foreach ($rows as $row) {
                $confirmed = $row->created_at->copy();

                if ($prev === null) {
                    $opened = $confirmed->copy()->subSeconds($baseline);
                } else {
                    $rawGap = (int) abs($confirmed->diffInSeconds($prev->created_at));
                    if ($rawGap > 30 * 60) {
                        // New session — back-date by the baseline rather than
                        // by the (huge) inter-session gap.
                        $opened = $confirmed->copy()->subSeconds($baseline);
                    } else {
                        $gap = max(3, min(300, $rawGap));
                        $opened = $confirmed->copy()->subSeconds($gap);
                    }
                }

                $plan[$row->id] = ['opened' => $opened, 'confirmed' => $confirmed];
                $prev = $row;
            }
        }

        return $plan;
    }

    /**
     * Synthesise a gap (seconds) for lesson rows that have no progress_data
     * timestamps. Uses the overall quiz-plan median when available so it
     * tracks the cohort's actual answering cadence; defaults to 25 s.
     */
    private function synthesisedGapSeconds(array $quizPlans): int
    {
        $gaps = [];
        foreach ($quizPlans as $entry) {
            $gaps[] = (int) abs($entry['confirmed']->diffInSeconds($entry['opened']));
        }

        return $this->medianClamped($gaps, 25, 3, 300);
    }

    /**
     * Median of `$values`, clamped to [$min, $max]. Returns $fallback when
     * the sample is empty.
     */
    private function medianClamped(array $values, int $fallback, int $min, int $max): int
    {
        if (empty($values)) {
            return $fallback;
        }
        sort($values);
        $n = count($values);
        $median = ($n % 2 === 1)
            ? $values[intdiv($n, 2)]
            : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);

        return (int) max($min, min($max, $median));
    }

    /**
     * Get completed block IDs from user progress records.
     */
    private function getCompletedBlockIds($userProgressRecords, array $allBlockIds, array $quizScores = []): array
    {
        $blockProgress = $this->buildBlockProgressMap($userProgressRecords);
        $completed = [];

        foreach ($allBlockIds as $blockId) {
            // Block is completed if it has lesson progress OR a quiz answer
            if (
                (isset($blockProgress[$blockId]) && ! empty($blockProgress[$blockId]['isCompleted']))
                || isset($quizScores[$blockId])
            ) {
                $completed[] = $blockId;
            }
        }

        return $completed;
    }
}
