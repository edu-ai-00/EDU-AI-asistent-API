<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockStat;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\QuizAttempt;
use App\Models\UserEloProfile;
use App\Models\UserProgress;
use App\Services\EloEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EloBackfillController extends Controller
{
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

        $blockStatsUpdated = [];

        foreach ($userIds as $userId) {
            try {
                // Load records per-user to avoid memory issues
                $userProgressRecords = UserProgress::where('course_id', $courseId)->where('user_id', $userId)->get();
                $userQuizAttempts = QuizAttempt::where('course_id', $courseId)->where('user_id', $userId)->get();

                $detail = DB::transaction(function () use (
                    $userId, $courseId, $blocksWithGpf, $blockIds,
                    $userProgressRecords, $userQuizAttempts, &$blockStatsUpdated
                ) {
                    return $this->processUser(
                        $userId, $courseId, $blocksWithGpf, $blockIds,
                        $userProgressRecords, $userQuizAttempts, $blockStatsUpdated
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

            // Get or create BlockStat
            $blockStat = BlockStat::firstOrCreate(
                ['block_id' => $blockId],
                ['item_pocet' => array_fill(0, EloEngine::GPF_DIMENSIONS, 0)]
            );
            $itemPocet = $blockStat->item_pocet ?? array_fill(0, EloEngine::GPF_DIMENSIONS, 0);

            // Run ELO engine
            $result = EloEngine::updateTask(
                $profilElo,
                $profilPocet,
                $block['relation_vector'],
                $block['elo_vector'],
                $itemPocet,
                $score
            );

            // Create EloInteraction record
            EloInteraction::create([
                'user_id' => $userId,
                'block_id' => $blockId,
                'course_id' => $block['course_id'],
                'source' => $source,
                'score' => $score,
                'profil_elo_snapshot' => $result['profil_elo'],
                'elo_vector_snapshot' => $result['elo_vector'],
                'updated_indices' => $result['updated_indices'],
            ]);

            // Update block stat
            $blockStat->update(['item_pocet' => $result['item_pocet']]);
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
     */
    private function buildQuizScoreMap($quizAttempts): array
    {
        $map = [];

        foreach ($quizAttempts as $attempt) {
            $answers = $attempt->answers ?? [];
            foreach ($answers as $answer) {
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
