<?php

namespace App\Http\Controllers\Api;

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
        ]);

        // Increment block stats if delta provided
        if (!empty($validated['item_pocet_delta'])) {
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
            ]);

            // Increment block stats if delta provided
            if (!empty($entry['item_pocet_delta'])) {
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
     */
    public function exportCsv(string $courseId): StreamedResponse
    {
        $interactions = EloInteraction::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId)
            ->orderBy('created_at', 'asc')
            ->get();

        // Build a lookup of block_timestamps from user_courses.progress_data
        // keyed by "{user_id}:{block_id}" → { opened_at, confirmed_at }
        $userIds = $interactions->pluck('user_id')->unique()->values()->all();
        $timestampMap = [];

        if (!empty($userIds)) {
            // user_courses.course_id is an integer FK to courses.id,
            // but $courseId is the string course_id field — resolve it.
            $courseRecord = Course::where('course_id', $courseId)->first();
            $courseIntId = $courseRecord?->id;

            $userCourses = $courseIntId
                ? UserCourse::where('course_id', $courseIntId)
                    ->whereIn('user_id', $userIds)
                    ->get()
                : collect();

            foreach ($userCourses as $uc) {
                $progressData = $uc->progress_data;
                if (!is_array($progressData) || empty($progressData['lessons'])) {
                    continue;
                }

                foreach ($progressData['lessons'] as $lessonData) {
                    $blockTimestamps = $lessonData['block_timestamps'] ?? [];
                    foreach ($blockTimestamps as $blockId => $ts) {
                        $key = $uc->user_id . ':' . $blockId;
                        $timestampMap[$key] = $ts;
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

        $filename = 'elo-export-' . $courseId . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($interactions, $timestampMap, $sourceLabels, $headers) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Write header with tab separator
            fputcsv($handle, $headers, "\t");

            foreach ($interactions as $i) {
                $tsKey = $i->user_id . ':' . $i->block_id;
                $ts = $timestampMap[$tsKey] ?? null;

                $openedAt = null;
                $confirmedAt = null;
                $duration = null;

                if ($ts) {
                    $openedAt = isset($ts['opened_at']) ? \Carbon\Carbon::parse($ts['opened_at']) : null;
                    $confirmedAt = isset($ts['confirmed_at']) ? \Carbon\Carbon::parse($ts['confirmed_at']) : null;
                } else {
                    // Fallback: use created_at as confirmed time
                    $confirmedAt = $i->created_at;
                }

                if ($openedAt && $confirmedAt) {
                    $diffSeconds = $confirmedAt->diffInSeconds($openedAt);
                    $duration = $diffSeconds . 's';
                }

                $row = [
                    $i->user_id,
                    $i->user->classroom_id ?? '',
                    $i->block_id,
                    $i->course_id,
                    $sourceLabels[$i->source] ?? $i->source ?? '',
                    round($i->score * 100) . '%',
                    is_array($i->updated_indices) ? implode(', ', $i->updated_indices) : '',
                    is_array($i->profil_elo_snapshot) ? implode(', ', $i->profil_elo_snapshot) : '',
                    is_array($i->elo_vector_snapshot) ? implode(', ', $i->elo_vector_snapshot) : '',
                    $i->created_at->format('j.n.Y'),
                    $openedAt ? $openedAt->format('H:i:s') : '',
                    $confirmedAt ? $confirmedAt->format('H:i:s') : '',
                    $duration ?? '',
                ];

                fputcsv($handle, $row, "\t");
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
        ]);
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
