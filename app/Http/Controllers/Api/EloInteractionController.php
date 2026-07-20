<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsExportTiming;
use App\Http\Controllers\Controller;
use App\Models\BlockStat;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\UserCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EloInteractionController extends Controller
{
    use FormatsExportTiming;

    /**
     * Log a single ELO interaction.
     * Optionally increments block_stats.item_pocet via item_pocet_delta.
     *
     * POST /api/elo/interactions
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'block_id' => 'required|string|max:255',
            'course_id' => 'required|string|max:255',
            'score' => 'required|numeric|min:0|max:1',
            'source' => 'nullable|string|in:lesson,quiz',
            'profil_elo_snapshot' => 'nullable|array',
            'profil_elo_snapshot.*' => 'nullable|numeric',
            'elo_vector_snapshot' => 'nullable|array',
            'elo_vector_snapshot.*' => 'nullable|numeric',
            'updated_indices' => 'nullable|array',
            'updated_indices.*' => 'nullable|integer',
            'item_pocet_delta' => 'nullable|array',
            'item_pocet_delta.*' => 'nullable|integer',
            'opened_at' => 'nullable|date',
            'confirmed_at' => 'nullable|date',
            'duration_ms' => 'nullable|integer|min:0',
        ]);

        $interaction = EloInteraction::create([
            'user_id' => $request->user()->id,
            'block_id' => $validated['block_id'],
            'course_id' => $validated['course_id'],
            'source' => $validated['source'] ?? null,
            'score' => $validated['score'],
            'profil_elo_snapshot' => $validated['profil_elo_snapshot'] ?? null,
            'elo_vector_snapshot' => $validated['elo_vector_snapshot'] ?? null,
            'updated_indices' => $validated['updated_indices'] ?? null,
            'opened_at' => $validated['opened_at'] ?? null,
            'confirmed_at' => $validated['confirmed_at'] ?? null,
            'duration_ms' => $this->resolveDuration(
                $validated['duration_ms'] ?? null,
                $validated['opened_at'] ?? null,
                $validated['confirmed_at'] ?? null,
            ),
        ]);

        // Increment block stats if delta provided
        if (! empty($validated['item_pocet_delta'])) {
            $this->applyItemPocetDelta($validated['block_id'], $validated['item_pocet_delta']);
        }

        return response()->json([
            'id' => $interaction->id,
            'created_at' => $interaction->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Batch log ELO interactions (for offline sync).
     * Accepts an array of interactions and processes each one.
     *
     * POST /api/elo/interactions/batch
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'interactions' => 'required|array|min:1',
            'interactions.*.block_id' => 'required|string|max:255',
            'interactions.*.course_id' => 'required|string|max:255',
            'interactions.*.score' => 'required|numeric|min:0|max:1',
            'interactions.*.source' => 'nullable|string|in:lesson,quiz',
            'interactions.*.profil_elo_snapshot' => 'nullable|array',
            'interactions.*.profil_elo_snapshot.*' => 'nullable|numeric',
            'interactions.*.elo_vector_snapshot' => 'nullable|array',
            'interactions.*.elo_vector_snapshot.*' => 'nullable|numeric',
            'interactions.*.updated_indices' => 'nullable|array',
            'interactions.*.updated_indices.*' => 'nullable|integer',
            'interactions.*.item_pocet_delta' => 'nullable|array',
            'interactions.*.item_pocet_delta.*' => 'nullable|integer',
            'interactions.*.opened_at' => 'nullable|date',
            'interactions.*.confirmed_at' => 'nullable|date',
            'interactions.*.duration_ms' => 'nullable|integer|min:0',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['interactions'] as $entry) {
            $interaction = EloInteraction::create([
                'user_id' => $userId,
                'block_id' => $entry['block_id'],
                'course_id' => $entry['course_id'],
                'source' => $entry['source'] ?? null,
                'score' => $entry['score'],
                'profil_elo_snapshot' => $entry['profil_elo_snapshot'] ?? null,
                'elo_vector_snapshot' => $entry['elo_vector_snapshot'] ?? null,
                'updated_indices' => $entry['updated_indices'] ?? null,
                'opened_at' => $entry['opened_at'] ?? null,
                'confirmed_at' => $entry['confirmed_at'] ?? null,
                'duration_ms' => $this->resolveDuration(
                    $entry['duration_ms'] ?? null,
                    $entry['opened_at'] ?? null,
                    $entry['confirmed_at'] ?? null,
                ),
            ]);

            // Increment block stats if delta provided
            if (! empty($entry['item_pocet_delta'])) {
                $this->applyItemPocetDelta($entry['block_id'], $entry['item_pocet_delta']);
            }

            $results[] = [
                'id' => $interaction->id,
                'block_id' => $interaction->block_id,
                'created_at' => $interaction->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Admin endpoints
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * List ELO interactions for a specific user (admin).
     *
     * GET /api/admin/elo/interactions/user/{userId}
     */
    public function adminByUser(Request $request, int $userId): JsonResponse
    {
        $query = EloInteraction::where('user_id', $userId);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $interactions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $interactions->map(fn ($i) => [
                'id' => $i->id,
                'block_id' => $i->block_id,
                'course_id' => $i->course_id,
                'source' => $i->source,
                'score' => $i->score,
                'profil_elo_snapshot' => $i->profil_elo_snapshot,
                'elo_vector_snapshot' => $i->elo_vector_snapshot,
                'updated_indices' => $i->updated_indices,
                'created_at' => $i->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $interactions->count(),
            ],
        ]);
    }

    /**
     * List ELO interactions for a course across all users (admin).
     *
     * GET /api/admin/elo/interactions/course/{courseId}
     */
    public function adminByCourse(string $courseId): JsonResponse
    {
        $interactions = EloInteraction::with('user:id,name,email')
            ->where('course_id', $courseId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $interactions->map(fn ($i) => [
                'id' => $i->id,
                'user' => $i->user ? [
                    'id' => $i->user->id,
                    'name' => $i->user->name,
                    'email' => $i->user->email,
                ] : null,
                'block_id' => $i->block_id,
                'source' => $i->source,
                'score' => $i->score,
                'profil_elo_snapshot' => $i->profil_elo_snapshot,
                'elo_vector_snapshot' => $i->elo_vector_snapshot,
                'updated_indices' => $i->updated_indices,
                'created_at' => $i->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $interactions->count(),
                'unique_users' => $interactions->pluck('user_id')->unique()->count(),
                'avg_score' => $interactions->count() > 0 ? round($interactions->avg('score'), 3) : null,
            ],
        ]);
    }

    /**
     * Export ELO interactions for a course as CSV.
     *
     * GET /api/admin/elo/interactions/course/{courseId}/export
     *
     * The export emits one row per block traversal, combining two sources:
     *
     *   1. `elo_interactions` — every block that produced an ELO update
     *      (question / evaluation blocks, lesson + quiz).
     *   2. `user_courses.progress_data.lessons[].block_timestamps` — every
     *      lesson block the student opened, including pure display / content
     *      blocks that don't carry an ELO score.
     *
     * Rows from (2) that have no matching ELO interaction still appear so
     * the export reflects the full lesson traversal. Timing columns prefer
     * the dedicated columns on `elo_interactions` (populated by the
     * client + backfill) and fall back to `progress_data` when needed.
     */
    public function exportCsv(string $courseId): StreamedResponse
    {
        $interactions = EloInteraction::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId)
            ->orderBy('created_at', 'asc')
            ->get();

        // Resolve the course's integer PK once so we can join user_courses.
        $courseRecord = Course::where('course_id', $courseId)->first();
        $courseIntId = $courseRecord?->id;

        // Collect every user that has either ELO data OR lesson progress
        // for this course — both are inputs to the export.
        $interactionUserIds = $interactions->pluck('user_id')->unique()->values()->all();
        $progressUserIds = $courseIntId
            ? UserCourse::where('course_id', $courseIntId)->pluck('user_id')->all()
            : [];
        $userIds = array_values(array_unique(array_merge($interactionUserIds, $progressUserIds)));

        // Pre-load users for classroom_id display.
        $userMap = [];
        if (! empty($userIds)) {
            foreach (\App\Models\User::whereIn('id', $userIds)->get(['id', 'classroom_id']) as $u) {
                $userMap[$u->id] = $u;
            }
        }

        // Build the lesson traversal map:
        //   "{user_id}:{block_id}" → { opened_at, confirmed_at }
        // sourced from user_courses.progress_data.lessons[].block_timestamps.
        $lessonTraversal = [];
        if ($courseIntId !== null && ! empty($userIds)) {
            $userCourses = UserCourse::where('course_id', $courseIntId)
                ->whereIn('user_id', $userIds)
                ->get();

            foreach ($userCourses as $uc) {
                $progressData = $uc->progress_data;
                if (! is_array($progressData) || empty($progressData['lessons'])) {
                    continue;
                }

                foreach ($progressData['lessons'] as $lessonData) {
                    $blockTimestamps = $lessonData['block_timestamps'] ?? [];
                    foreach ($blockTimestamps as $blockId => $ts) {
                        $key = $uc->user_id.':'.$blockId;
                        $lessonTraversal[$key] = $ts;
                    }
                }
            }
        }

        $sourceLabels = ['lesson' => 'Lekce', 'quiz' => 'Kvíz'];

        $headers = [
            'ID žáka (interní)',
            'ID třídy (interní)',
            'Blok',
            'Kurz',
            'Zdroj',
            'Skóre',
            'Dimenze',
            'ELO snapshot (kompletní, po úloze)',
            'ELO úlohy (kompletní, po úloze)',
            'Den',
            'Otevřeno',
            'Potvrzeno',
            'Doba',
        ];

        $filename = 'elo-export-'.$courseId.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use (
            $interactions, $lessonTraversal,
            $sourceLabels, $headers, $userMap, $courseId
        ) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Write header with tab separator
            fputcsv($handle, $headers, "\t");

            // ── Pass 1: emit one row per ELO interaction (lesson + quiz). ──
            // Track which (user, block, lesson-source) pairs we've already
            // written so Pass 2 doesn't duplicate them.
            $emittedLessonKeys = [];

            foreach ($interactions as $i) {
                $tsKey = $i->user_id.':'.$i->block_id;
                $progressTs = $lessonTraversal[$tsKey] ?? null;

                // Prefer the dedicated columns on elo_interactions, fall back
                // to the progress_data block_timestamps. created_at remains
                // the final fallback for confirmed_at.
                $openedAt = $i->opened_at
                    ?? ($progressTs && isset($progressTs['opened_at'])
                        ? \Carbon\Carbon::parse($progressTs['opened_at'])
                        : null);

                $confirmedAt = $i->confirmed_at
                    ?? ($progressTs && isset($progressTs['confirmed_at'])
                        ? \Carbon\Carbon::parse($progressTs['confirmed_at'])
                        : $i->created_at);

                $duration = $this->formatDurationCell($i->duration_ms, $openedAt, $confirmedAt);

                fputcsv($handle, [
                    $i->user_id,
                    $i->user->classroom_id ?? '',
                    $i->block_id,
                    $i->course_id,
                    $sourceLabels[$i->source] ?? $i->source ?? '',
                    round($i->score * 100).'%',
                    is_array($i->updated_indices) ? implode(', ', $i->updated_indices) : '',
                    is_array($i->profil_elo_snapshot) ? implode(', ', $i->profil_elo_snapshot) : '',
                    is_array($i->elo_vector_snapshot) ? implode(', ', $i->elo_vector_snapshot) : '',
                    ($confirmedAt ?? $i->created_at)->format('j.n.Y'),
                    $openedAt ? $openedAt->format('H:i:s') : '',
                    $confirmedAt ? $confirmedAt->format('H:i:s') : '',
                    $duration,
                ], "\t");

                if (($i->source ?? 'lesson') === 'lesson') {
                    $emittedLessonKeys[$tsKey] = true;
                }
            }

            // ── Pass 2: emit traversal rows for lesson blocks that had no
            // ELO interaction (display / content blocks). These give the
            // full lesson traversal picture, with timing but no score / ELO. ──
            foreach ($lessonTraversal as $key => $ts) {
                if (isset($emittedLessonKeys[$key])) {
                    continue;
                }

                [$userId, $blockId] = explode(':', $key, 2);
                $userId = (int) $userId;

                $openedAt = isset($ts['opened_at']) ? \Carbon\Carbon::parse($ts['opened_at']) : null;
                $confirmedAt = isset($ts['confirmed_at']) ? \Carbon\Carbon::parse($ts['confirmed_at']) : null;

                // Skip rows that have no usable timing at all — they would be
                // noise without any answer / score data attached.
                if ($openedAt === null && $confirmedAt === null) {
                    continue;
                }

                $duration = $this->formatDurationCell(null, $openedAt, $confirmedAt);
                $dayAnchor = $confirmedAt ?? $openedAt;

                fputcsv($handle, [
                    $userId,
                    $userMap[$userId]->classroom_id ?? '',
                    $blockId,
                    $courseId,
                    $sourceLabels['lesson'],
                    '', // no score
                    '', // no updated_indices
                    '', // no profil_elo_snapshot
                    '', // no elo_vector_snapshot
                    $dayAnchor ? $dayAnchor->format('j.n.Y') : '',
                    $openedAt ? $openedAt->format('H:i:s') : '',
                    $confirmedAt ? $confirmedAt->format('H:i:s') : '',
                    $duration,
                ], "\t");
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
        ]);
    }

    /**
     * Resolve the final `duration_ms` value from the client payload.
     *
     * If the client passes an explicit `duration_ms`, trust it. Otherwise
     * compute it from `opened_at` and `confirmed_at` so callers can supply
     * just the two ends and let the server derive the delta.
     */
    private function resolveDuration(?int $duration, ?string $openedAt, ?string $confirmedAt): ?int
    {
        if ($duration !== null) {
            return $duration;
        }

        if ($openedAt !== null && $confirmedAt !== null) {
            try {
                $opened = \Carbon\Carbon::parse($openedAt);
                $confirmed = \Carbon\Carbon::parse($confirmedAt);
                $delta = $confirmed->diffInMilliseconds($opened);

                return $delta >= 0 ? (int) $delta : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Apply item_pocet_delta increments to the block_stats record.
     * Upserts the BlockStat and element-wise adds the delta to item_pocet.
     */
    private function applyItemPocetDelta(string $blockId, array $delta): void
    {
        $blockStat = BlockStat::firstOrCreate(
            ['block_id' => $blockId],
            ['item_pocet' => array_fill(0, count($delta), 0)]
        );

        $current = $blockStat->item_pocet ?? array_fill(0, count($delta), 0);

        // Element-wise addition
        $updated = [];
        for ($i = 0; $i < count($delta); $i++) {
            $updated[$i] = ($current[$i] ?? 0) + ($delta[$i] ?? 0);
        }

        $blockStat->update(['item_pocet' => $updated]);
    }
}
