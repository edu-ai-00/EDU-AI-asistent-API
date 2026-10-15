<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsExportTiming;
use App\Http\Controllers\Concerns\LoadsHintHelpUsage;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\UserCourse;
use App\Services\AuditLogger;
use App\Services\TaskCalibrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EloInteractionController extends Controller
{
    use FormatsExportTiming, LoadsHintHelpUsage;

    /**
     * Log a single ELO interaction and apply its task half on the server
     * (BR-697NCA, see TaskCalibrationService). A retry carrying a
     * client_uuid the server already stored returns that row unchanged.
     *
     * `item_pocet_delta` and `elo_vector_snapshot` are still accepted from
     * older builds but ignored: the server is the only writer of D and m, and
     * the snapshot is the server's D after this answer.
     *
     * POST /api/elo/interactions
     */
    public function store(Request $request, TaskCalibrationService $calibration): JsonResponse
    {
        $validated = $request->validate($this->interactionRules(''));

        [$interaction, $created] = $calibration->record($request->user(), $this->interactionAttributes($validated));

        return response()->json([
            'id' => $interaction->id,
            'created_at' => $interaction->created_at->toIso8601String(),
            'task_applied' => $interaction->task_applied,
        ], $created ? 201 : 200);
    }

    /**
     * Batch log ELO interactions (for offline sync).
     *
     * One transaction per item, so an item stored before a failure is never
     * applied twice when the app falls back to sending them one by one — its
     * client_uuid finds the stored row.
     *
     * POST /api/elo/interactions/batch
     */
    public function storeBatch(Request $request, TaskCalibrationService $calibration): JsonResponse
    {
        $validated = $request->validate([
            'interactions' => 'required|array|min:1',
            ...$this->interactionRules('interactions.*.'),
        ]);

        $user = $request->user();
        $results = [];

        foreach ($validated['interactions'] as $entry) {
            [$interaction] = $calibration->record($user, $this->interactionAttributes($entry));

            $results[] = [
                'id' => $interaction->id,
                'block_id' => $interaction->block_id,
                'created_at' => $interaction->created_at->toIso8601String(),
                'task_applied' => $interaction->task_applied,
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * Validation rules for one interaction, under $prefix.
     *
     * @return array<string, string>
     */
    private function interactionRules(string $prefix): array
    {
        $rules = [
            'client_uuid' => 'nullable|string|max:64',
            'block_id' => 'required|string|max:255',
            'course_id' => 'required|string|max:255',
            'score' => 'required|numeric|min:0|max:1',
            'source' => 'nullable|string|in:lesson,quiz',
            'profil_elo_before' => 'nullable|array',
            'profil_elo_before.*' => 'nullable|numeric',
            'profil_elo_snapshot' => 'nullable|array',
            'profil_elo_snapshot.*' => 'nullable|numeric',
            'elo_vector_used' => 'nullable|array',
            'elo_vector_used.*' => 'nullable|numeric',
            // Sent by builds up to 1.0.24+34; ignored (see store()).
            'elo_vector_snapshot' => 'nullable|array',
            'elo_vector_snapshot.*' => 'nullable|numeric',
            'updated_indices' => 'nullable|array',
            'updated_indices.*' => 'nullable|integer',
            'item_pocet_delta' => 'nullable|array',
            'item_pocet_delta.*' => 'nullable|integer',
            'opened_at' => 'nullable|date',
            'confirmed_at' => 'nullable|date',
            'duration_ms' => 'nullable|integer|min:0',
            'elo_config_version' => 'nullable|string|max:64',
        ];

        $prefixed = [];
        foreach ($rules as $field => $rule) {
            $prefixed[$prefix.$field] = $rule;
        }

        return $prefixed;
    }

    /**
     * The EloInteraction attributes the app decides. The server adds the
     * user and the calibration outcome (task_applied, task_skip_reason,
     * elo_vector_snapshot).
     *
     * @return array<string, mixed>
     */
    private function interactionAttributes(array $entry): array
    {
        return [
            'client_uuid' => $entry['client_uuid'] ?? null,
            'block_id' => $entry['block_id'],
            'course_id' => $entry['course_id'],
            'source' => $entry['source'] ?? null,
            'score' => $entry['score'],
            'profil_elo_before' => $entry['profil_elo_before'] ?? null,
            'profil_elo_snapshot' => $entry['profil_elo_snapshot'] ?? null,
            'elo_vector_used' => $entry['elo_vector_used'] ?? null,
            'updated_indices' => $entry['updated_indices'] ?? null,
            'opened_at' => $entry['opened_at'] ?? null,
            'confirmed_at' => $entry['confirmed_at'] ?? null,
            'duration_ms' => $this->resolveDuration(
                $entry['duration_ms'] ?? null,
                $entry['opened_at'] ?? null,
                $entry['confirmed_at'] ?? null,
            ),
            // The app computed this update, so its engine version is the one
            // that applies (BR-EBNMBF). Never defaulted server-side.
            'elo_config_version' => $entry['elo_config_version'] ?? null,
        ];
    }

    /**
     * How many of a block's most recent reaction times the gaming reference
     * carries. The robust threshold is a median, so a recent window estimates
     * it as well as the full history while keeping the payload bounded.
     */
    public const GAMING_REFERENCE_LIMIT = 200;

    /**
     * Previous valid, non-gaming reaction times per task, for the Pilot 2
     * robust gaming threshold (BR-H3TCRE). The app feeds them to
     * GamingGuard.classify: under ten it keeps the fixed 2 s floor, from ten on
     * it uses median/MAD over their log-times.
     *
     * The rows are the quiz interactions the app already logs — it only logs
     * an answer that was not a guess, with the display → submit time it
     * measured — from students across every school. Left out:
     *   - lesson rows: their timing is persisted separately and older rows
     *     predate the lesson gaming guard (BR-3N79C3);
     *   - teacher/admin rows: staff checking a quiz are not the task's students;
     *   - whole-second durations: runTimestampBackfill fills pre-tracking quiz
     *     rows with estimates built from second-precision created_at, while
     *     the app always sends the millisecond duration it measured. Dropping
     *     the 1-in-1000 real answer that lands on a whole second costs nothing.
     *
     * GET /api/elo/gaming-reference?block_ids=a,b
     * → { data: { "<block_id>": [seconds, …] } } — every requested block, [] when none.
     */
    public function gamingReference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'block_ids' => 'required|string|max:20000',
        ]);

        $blockIds = array_slice(array_values(array_unique(array_filter(
            array_map('trim', explode(',', $validated['block_ids'])),
            fn (string $id) => $id !== ''
        ))), 0, 200);

        $ranked = DB::table('elo_interactions')
            ->join('users', 'users.id', '=', 'elo_interactions.user_id')
            ->whereIn('elo_interactions.block_id', $blockIds)
            ->where('elo_interactions.source', 'quiz')
            ->whereNotIn('users.role', ['admin', 'teacher'])
            ->where('elo_interactions.duration_ms', '>', 0)
            ->whereRaw('elo_interactions.duration_ms % 1000 <> 0')
            ->select('elo_interactions.block_id', 'elo_interactions.duration_ms')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY elo_interactions.block_id ORDER BY elo_interactions.id DESC) AS rn');

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::GAMING_REFERENCE_LIMIT)
            ->get(['block_id', 'duration_ms']);

        $reference = array_fill_keys($blockIds, []);
        foreach ($rows as $row) {
            $reference[$row->block_id][] = ((int) $row->duration_ms) / 1000;
        }

        // An object even when empty or when block ids look numeric.
        return response()->json(['data' => (object) $reference]);
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
     *
     * The trailing hint / help / assistant columns report every time the
     * student opened assistance on that block (BR-SPYYKR), so a fast answer
     * that followed a help open is distinguishable from an unaided one.
     *
     * "Verze ELO" is the ELO configuration the row was computed with
     * (BR-EBNMBF). It sits last so formulas written against the earlier
     * columns keep their positions; rows without an ELO interaction, and
     * interactions logged before the version was recorded, leave it empty.
     */
    public function exportCsv(AuditLogger $audit, string $courseId): StreamedResponse
    {
        $interactions = EloInteraction::with('user:id,name,email', 'user.classrooms:classrooms.id')
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

        // Pre-load users for the classroom column.
        $userMap = [];
        if (! empty($userIds)) {
            foreach (\App\Models\User::whereIn('id', $userIds)->with('classrooms:classrooms.id')->get(['id']) as $u) {
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

        // Assistance opens keyed "{user_id}:{block_id}", merged across the
        // lesson / quiz / user_progress copies the client writes.
        $hintHelp = $this->buildHintHelpMap($courseId, $courseIntId, $userIds);

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
            ...$this->hintHelpHeaders(),
            'Verze ELO',
        ];

        $filename = 'elo-export-'.$courseId.'-'.now()->format('Y-m-d').'.csv';

        $audit->log('export.elo_interactions', $courseRecord, meta: [
            'course_id' => $courseId,
            'student_count' => count($userIds),
            'format' => 'csv',
        ]);

        return response()->streamDownload(function () use (
            $interactions, $lessonTraversal, $hintHelp,
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
                    $i->user ? $i->user->classrooms->pluck('id')->implode(';') : '',
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
                    ...$this->hintHelpCells($hintHelp[$tsKey] ?? null),
                    $i->elo_config_version ?? '',
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
                    isset($userMap[$userId]) ? $userMap[$userId]->classrooms->pluck('id')->implode(';') : '',
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
                    ...$this->hintHelpCells($hintHelp[$key] ?? null),
                    '', // no ELO interaction → no config version
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
}
