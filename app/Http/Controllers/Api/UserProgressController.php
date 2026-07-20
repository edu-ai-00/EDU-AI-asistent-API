<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsExportTiming;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\UserCourse;
use App\Models\UserProgress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserProgressController extends Controller
{
    use FormatsExportTiming;

    /**
     * Bulk upsert lesson progress.
     *
     * POST /api/user/progress
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'progress' => 'required|array|min:1',
            'progress.*.course_id' => 'required|string|max:255',
            'progress.*.lesson_id' => 'required|string|max:255',
            'progress.*.progress_percent' => 'sometimes|integer|min:0|max:100',
            'progress.*.is_completed' => 'sometimes|boolean',
            'progress.*.last_position' => 'sometimes|integer|min:0',
            'progress.*.time_spent_seconds' => 'sometimes|integer|min:0',
            'progress.*.progress_data' => 'sometimes|array',
            'progress.*.started_at' => 'sometimes|nullable|date',
            'progress.*.completed_at' => 'sometimes|nullable|date',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['progress'] as $entry) {
            $existing = UserProgress::where('user_id', $userId)
                ->where('course_id', $entry['course_id'])
                ->where('lesson_id', $entry['lesson_id'])
                ->first();

            // Deep-merge progress_data so likes, step_progress, etc. aren't lost
            $incomingData = $entry['progress_data'] ?? [];
            $mergedData = $existing
                ? $this->deepMergeProgressData($existing->progress_data ?? [], $incomingData)
                : $incomingData;

            $record = UserProgress::updateOrCreate(
                [
                    'user_id' => $userId,
                    'course_id' => $entry['course_id'],
                    'lesson_id' => $entry['lesson_id'],
                ],
                [
                    'progress_percent' => $entry['progress_percent'] ?? ($existing->progress_percent ?? 0),
                    'is_completed' => $entry['is_completed'] ?? ($existing->is_completed ?? false),
                    'last_position' => $entry['last_position'] ?? ($existing->last_position ?? 0),
                    'time_spent_seconds' => $entry['time_spent_seconds'] ?? ($existing->time_spent_seconds ?? 0),
                    'progress_data' => $mergedData,
                    'started_at' => $entry['started_at'] ?? ($existing->started_at ?? null),
                    'completed_at' => $entry['completed_at'] ?? ($existing->completed_at ?? null),
                ]
            );

            $results[] = [
                'id' => $record->id,
                'course_id' => $record->course_id,
                'lesson_id' => $record->lesson_id,
                'updated_at' => $record->updated_at->toIso8601String(),
            ];
        }

        return response()->json([
            'progress' => $results,
        ]);
    }

    /**
     * Get all progress for the authenticated user.
     * Supports ?since= for incremental pull.
     *
     * GET /api/user/progress
     */
    public function index(Request $request): JsonResponse
    {
        $query = UserProgress::where('user_id', $request->user()->id);

        if ($request->filled('since')) {
            $query->where('updated_at', '>=', $request->input('since'));
        }

        $progress = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'progress' => $progress->map(fn ($p) => [
                'id' => $p->id,
                'course_id' => $p->course_id,
                'lesson_id' => $p->lesson_id,
                'progress_percent' => $p->progress_percent,
                'is_completed' => $p->is_completed,
                'last_position' => $p->last_position,
                'time_spent_seconds' => $p->time_spent_seconds,
                'started_at' => $p->started_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'progress_data' => $p->progress_data,
                'updated_at' => $p->updated_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Deep-merge progress_data so existing fields (likes, step_progress) aren't lost
     * when the client sends a partial update.
     */
    private function deepMergeProgressData(array $existing, array $incoming): array
    {
        // Merge blocks: per-block data is merged individually
        if (isset($incoming['blocks']) || isset($existing['blocks'])) {
            $existingBlocks = $existing['blocks'] ?? [];
            $incomingBlocks = $incoming['blocks'] ?? [];

            foreach ($incomingBlocks as $blockId => $blockData) {
                if (isset($existingBlocks[$blockId]) && is_array($existingBlocks[$blockId]) && is_array($blockData)) {
                    $existingBlocks[$blockId] = array_merge($existingBlocks[$blockId], $blockData);
                } else {
                    $existingBlocks[$blockId] = $blockData;
                }
            }

            $existing['blocks'] = $existingBlocks;
            unset($incoming['blocks']);
        }

        // Merge step_progress the same way
        if (isset($incoming['step_progress']) || isset($existing['step_progress'])) {
            $existingSteps = $existing['step_progress'] ?? [];
            $incomingSteps = $incoming['step_progress'] ?? [];

            foreach ($incomingSteps as $stepId => $stepData) {
                if (isset($existingSteps[$stepId]) && is_array($existingSteps[$stepId]) && is_array($stepData)) {
                    $existingSteps[$stepId] = array_merge($existingSteps[$stepId], $stepData);
                } else {
                    $existingSteps[$stepId] = $stepData;
                }
            }

            $existing['step_progress'] = $existingSteps;
            unset($incoming['step_progress']);
        }

        // Merge block_feedback per-block (isLiked, isDisliked)
        if (isset($incoming['block_feedback']) || isset($existing['block_feedback'])) {
            $existingFeedback = $existing['block_feedback'] ?? [];
            $incomingFeedback = $incoming['block_feedback'] ?? [];

            foreach ($incomingFeedback as $blockId => $feedbackData) {
                if (isset($existingFeedback[$blockId]) && is_array($existingFeedback[$blockId]) && is_array($feedbackData)) {
                    $existingFeedback[$blockId] = array_merge($existingFeedback[$blockId], $feedbackData);
                } else {
                    $existingFeedback[$blockId] = $feedbackData;
                }
            }

            $existing['block_feedback'] = $existingFeedback;
            unset($incoming['block_feedback']);
        }

        // Top-level keys: incoming wins
        return array_merge($existing, $incoming);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Admin endpoints
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * List all progress records for a course (admin).
     * Supports ?lesson_id= filter.
     * Returns progress with user info, grouped by lesson.
     *
     * GET /api/admin/progress/{courseId}
     */
    public function adminByCourse(Request $request, string $courseId): JsonResponse
    {
        $query = UserProgress::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId);

        if ($request->filled('lesson_id')) {
            $query->where('lesson_id', $request->input('lesson_id'));
        }

        $progress = $query->orderBy('lesson_id')->orderBy('updated_at', 'desc')->get();

        // Load the course data to get lesson/block structure
        $course = Course::where('course_id', $courseId)->first();
        $courseLessons = null;
        if ($course && $course->data) {
            $courseLessons = collect($course->data['lessons'] ?? [])->map(fn ($l) => [
                'id' => $l['id'] ?? null,
                'title' => $l['title'] ?? $l['name'] ?? null,
            ]);
        }

        return response()->json([
            'course_id' => $courseId,
            'course_name' => $course?->name,
            'course_lessons' => $courseLessons,
            'data' => $progress->map(fn ($p) => [
                'id' => $p->id,
                'user' => $p->user ? [
                    'id' => $p->user->id,
                    'name' => $p->user->name,
                    'email' => $p->user->email,
                    'classroom_id' => $p->user->classroom_id,
                ] : null,
                'lesson_id' => $p->lesson_id,
                'progress_percent' => $p->progress_percent,
                'is_completed' => $p->is_completed,
                'last_position' => $p->last_position,
                'time_spent_seconds' => $p->time_spent_seconds,
                'started_at' => $p->started_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'progress_data' => $p->progress_data,
                'updated_at' => $p->updated_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $progress->count(),
            ],
        ]);
    }

    /**
     * Get all progress for a specific user (admin).
     * Supports ?course_id= filter.
     *
     * GET /api/admin/progress/user/{userId}
     */
    public function adminByUser(Request $request, int $userId): JsonResponse
    {
        $query = UserProgress::where('user_id', $userId);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $progress = $query->orderBy('course_id')->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'data' => $progress->map(fn ($p) => [
                'id' => $p->id,
                'course_id' => $p->course_id,
                'lesson_id' => $p->lesson_id,
                'progress_percent' => $p->progress_percent,
                'is_completed' => $p->is_completed,
                'last_position' => $p->last_position,
                'time_spent_seconds' => $p->time_spent_seconds,
                'started_at' => $p->started_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'progress_data' => $p->progress_data,
                'updated_at' => $p->updated_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $progress->count(),
            ],
        ]);
    }

    /**
     * Get aggregated stats per block for a course (admin).
     * Shows how many users answered each block, correct/incorrect counts.
     *
     * GET /api/admin/progress/{courseId}/stats
     */
    public function adminCourseStats(string $courseId): JsonResponse
    {
        $records = UserProgress::where('course_id', $courseId)
            ->whereNotNull('progress_data')
            ->get(['lesson_id', 'progress_data']);

        // Aggregate block-level stats across all users
        $blockStats = [];
        foreach ($records as $record) {
            $blocks = $record->progress_data['blocks'] ?? [];
            foreach ($blocks as $blockId => $blockData) {
                if (! isset($blockStats[$blockId])) {
                    $blockStats[$blockId] = [
                        'block_id' => $blockId,
                        'lesson_id' => $record->lesson_id,
                        'total_attempts' => 0,
                        'completed_count' => 0,
                        'correct_count' => 0,
                        'incorrect_count' => 0,
                        'liked_count' => 0,
                    ];
                }
                $blockStats[$blockId]['total_attempts']++;
                if (! empty($blockData['isCompleted'])) {
                    $blockStats[$blockId]['completed_count']++;
                }
                if (isset($blockData['isCorrect'])) {
                    if ($blockData['isCorrect']) {
                        $blockStats[$blockId]['correct_count']++;
                    } else {
                        $blockStats[$blockId]['incorrect_count']++;
                    }
                }
                if (! empty($blockData['isLiked'])) {
                    $blockStats[$blockId]['liked_count']++;
                }
            }
        }

        return response()->json([
            'course_id' => $courseId,
            'blocks' => array_values($blockStats),
            'meta' => [
                'total_users' => $records->count(),
                'total_blocks' => count($blockStats),
            ],
        ]);
    }

    /**
     * Per-student concrete answers for a course (admin / teacher).
     *
     * Surfaces the actual chosen option(s) and open-ended text that the
     * aggregate /stats endpoint hides. Source of truth is
     * user_progress.progress_data.step_progress[blockId].stepAnswers — this
     * is captured for every answered step, including attitude/questionnaire
     * blocks that have no correct answer.
     *
     * GET /api/admin/progress/{courseId}/answers
     */
    public function adminCourseAnswers(string $courseId): JsonResponse
    {
        $course = Course::where('course_id', $courseId)->first();
        $answerKey = $this->buildAnswerKeyForCourse($course?->data);

        $records = UserProgress::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId)
            ->orderBy('user_id')
            ->orderBy('lesson_id')
            ->get();

        $rows = [];
        foreach ($records as $p) {
            foreach ($this->extractAnswerRows($p, $answerKey) as $row) {
                $rows[] = [
                    'user_id' => $p->user_id,
                    'user_name' => $p->user?->name,
                    'user_email' => $p->user?->email,
                    'classroom_id' => $p->user?->classroom_id,
                    'lesson_id' => $p->lesson_id,
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ] + $row;
            }
        }

        return response()->json([
            'course_id' => $courseId,
            'course_name' => $course?->name,
            'data' => $rows,
            'meta' => [
                'total_users' => $records->pluck('user_id')->unique()->count(),
                'total_answers' => count($rows),
            ],
        ]);
    }

    /**
     * CSV (tab-separated, Excel-friendly) export of per-student answers.
     *
     * GET /api/admin/progress/{courseId}/answers/export
     */
    public function adminCourseAnswersExport(string $courseId): StreamedResponse
    {
        $course = Course::where('course_id', $courseId)->first();
        $answerKey = $this->buildAnswerKeyForCourse($course?->data);

        $records = UserProgress::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId)
            ->orderBy('user_id')
            ->orderBy('lesson_id')
            ->get();

        // Per-block timing (Den/Otevřeno/Potvrzeno/Doba), keyed "user:block",
        // mirroring the ELO export so both files show the same values.
        $timing = $this->buildAnswerTimingMap($courseId, $course?->id, $records->pluck('user_id')->unique()->all());

        $headers = [
            'ID žáka (interní)',
            'Jméno',
            'E-mail',
            'ID třídy (interní)',
            'Lekce',
            'Blok',
            'Krok',
            'Otázka',
            'Typ otázky',
            'Odpověď (ID možnosti)',
            'Odpověď (text možnosti)',
            'Otevřená odpověď',
            'Správně',
            'Den',
            'Otevřeno',
            'Potvrzeno',
            'Doba',
        ];

        $filename = 'odpovedi-'.$courseId.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($records, $answerKey, $headers, $timing) {
            $handle = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, "\t");

            foreach ($records as $p) {
                foreach ($this->extractAnswerRows($p, $answerKey) as $row) {
                    $timingCells = $this->timingCells($timing[$p->user_id.':'.$row['block_id']] ?? null);

                    fputcsv($handle, array_map([$this, 'csvCell'], [
                        $p->user_id,
                        $p->user?->name ?? '',
                        $p->user?->email ?? '',
                        $p->user?->classroom_id ?? '',
                        $p->lesson_id,
                        $row['block_id'],
                        $row['step_id'],
                        $row['question'],
                        $row['question_type'],
                        $row['selected_option_ids'],
                        $row['selected_option_texts'],
                        $row['open_text'],
                        $row['is_correct'] === null ? '' : ($row['is_correct'] ? 'ano' : 'ne'),
                        ...$timingCells,
                    ]), "\t");
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
        ]);
    }

    /**
     * Build per-block timing keyed "{user_id}:{block_id}" for the answers
     * export. Mirrors the ELO export's precedence: prefer the dedicated
     * columns on `elo_interactions` (opened_at/confirmed_at/duration_ms),
     * fall back to `user_courses.progress_data.lessons[].block_timestamps`,
     * and finally use the interaction's created_at for "Potvrzeno".
     *
     * @param  array<int>  $userIds
     * @return array<string, array{opened: ?Carbon, confirmed: ?Carbon, duration_ms: ?int}>
     */
    private function buildAnswerTimingMap(string $courseId, ?int $courseIntId, array $userIds): array
    {
        $map = [];
        if (empty($userIds)) {
            return $map;
        }

        // Base layer: lesson block_timestamps (opened/confirmed only).
        if ($courseIntId !== null) {
            $userCourses = UserCourse::where('course_id', $courseIntId)
                ->whereIn('user_id', $userIds)
                ->get();

            foreach ($userCourses as $uc) {
                $progressData = $uc->progress_data;
                if (! is_array($progressData) || empty($progressData['lessons'])) {
                    continue;
                }

                foreach ($progressData['lessons'] as $lessonData) {
                    foreach (($lessonData['block_timestamps'] ?? []) as $blockId => $ts) {
                        $map[$uc->user_id.':'.$blockId] = [
                            'opened' => isset($ts['opened_at']) ? Carbon::parse($ts['opened_at']) : null,
                            'confirmed' => isset($ts['confirmed_at']) ? Carbon::parse($ts['confirmed_at']) : null,
                            'duration_ms' => null,
                        ];
                    }
                }
            }
        }

        // Preferred layer: elo_interactions dedicated columns. Iterate
        // ascending so the latest attempt per block wins.
        $interactions = EloInteraction::where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->orderBy('created_at')
            ->get();

        foreach ($interactions as $i) {
            $key = $i->user_id.':'.$i->block_id;
            $base = $map[$key] ?? ['opened' => null, 'confirmed' => null, 'duration_ms' => null];

            $map[$key] = [
                'opened' => $i->opened_at ?? $base['opened'],
                'confirmed' => $i->confirmed_at ?? $base['confirmed'] ?? $i->created_at,
                'duration_ms' => $i->duration_ms,
            ];
        }

        return $map;
    }

    /**
     * Build a lookup from the course JSON:
     *   blockId => [
     *     'question'  => string (joined text-step contents),
     *     'steps'     => [ stepId => ['type', 'options' => [id => text], 'correct' => [ids]] ],
     *   ]
     */
    private function buildAnswerKeyForCourse(?array $courseData): array
    {
        $key = [];
        foreach (($courseData['blocks'] ?? []) as $block) {
            $blockId = $block['block_id'] ?? null;
            if ($blockId === null) {
                continue;
            }

            $promptParts = [];
            $steps = [];
            foreach (($block['steps'] ?? []) as $step) {
                $type = $step['type'] ?? null;
                if ($type === 'text' && ! empty($step['content'])) {
                    $promptParts[] = $step['content'];
                }
                if ($type === 'question' && isset($step['question'])) {
                    $q = $step['question'];
                    $options = [];
                    $correct = [];
                    foreach (($q['options'] ?? []) as $opt) {
                        $oid = $opt['id'] ?? null;
                        if ($oid === null) {
                            continue;
                        }
                        $options[$oid] = $opt['text'] ?? '';
                        if (! empty($opt['is_correct'])) {
                            $correct[] = $oid;
                        }
                    }
                    $steps[$step['id'] ?? ('s'.count($steps))] = [
                        'type' => $q['type'] ?? 'unknown',
                        'options' => $options,
                        'correct' => $correct,
                    ];
                }
            }

            $key[$blockId] = [
                'question' => trim(implode(' ', $promptParts)),
                'steps' => $steps,
            ];
        }

        return $key;
    }

    /**
     * Flatten one UserProgress row into per-answer rows using the answer key.
     */
    private function extractAnswerRows(UserProgress $p, array $answerKey): array
    {
        $stepProgress = $p->progress_data['step_progress'] ?? [];
        if (! is_array($stepProgress)) {
            return [];
        }

        $rows = [];
        foreach ($stepProgress as $blockId => $blockState) {
            $stepAnswers = $blockState['stepAnswers'] ?? [];
            if (! is_array($stepAnswers)) {
                continue;
            }

            $blockMeta = $answerKey[$blockId] ?? ['question' => '', 'steps' => []];

            foreach ($stepAnswers as $stepId => $ans) {
                if (! is_array($ans)) {
                    continue;
                }

                $stepMeta = $blockMeta['steps'][$stepId] ?? ['type' => 'unknown', 'options' => [], 'correct' => []];

                // Normalise selected option ids (single or multi-select).
                $optionIds = [];
                if (isset($ans['selectedOptionId']) && $ans['selectedOptionId'] !== null) {
                    $optionIds[] = $ans['selectedOptionId'];
                }
                if (isset($ans['selectedOptionIds']) && is_array($ans['selectedOptionIds'])) {
                    $optionIds = array_merge($optionIds, $ans['selectedOptionIds']);
                }
                $optionIds = array_values(array_unique($optionIds));

                $optionTexts = array_map(
                    fn ($id) => $stepMeta['options'][$id] ?? $id,
                    $optionIds
                );

                $rows[] = [
                    'block_id' => $blockId,
                    'step_id' => $stepId,
                    'question' => $blockMeta['question'],
                    'question_type' => $stepMeta['type'],
                    'selected_option_ids' => implode(', ', $optionIds),
                    'selected_option_texts' => implode(' | ', $optionTexts),
                    'open_text' => $ans['textAnswer'] ?? '',
                    'is_correct' => array_key_exists('isCorrect', $ans) ? (bool) $ans['isCorrect'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Sanitise a value for a tab-separated cell (strip tabs/newlines).
     */
    private function csvCell($value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
    }
}
