<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkillEventLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillEventController extends Controller
{
    /**
     * Batch insert skill event logs.
     *
     * POST /api/skill-events
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => 'required|array|min:1|max:500',
            'events.*.course_id' => 'nullable|string|max:255',
            'events.*.session_id' => 'nullable|string|max:255',
            'events.*.block_id' => 'nullable|string|max:255',
            'events.*.dimension_indices' => 'nullable|array',
            'events.*.dimension_indices.*' => 'integer|min:0',
            'events.*.delta_values' => 'nullable|array',
            'events.*.delta_values.*' => 'numeric',
            'events.*.score' => 'required|numeric|min:0|max:1',
            'events.*.ts_bubble_open' => 'nullable|integer',
            'events.*.ts_answer_click' => 'nullable|integer',
            'events.*.ts_answer_submit' => 'nullable|integer',
            'events.*.is_correct' => 'nullable|boolean',
            'events.*.attempt_count' => 'nullable|integer|min:1',
            'events.*.help_used' => 'nullable|boolean',
            'events.*.time_on_task_ms' => 'nullable|integer|min:0',
            'events.*.extra_events_json' => 'nullable|array',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['events'] as $event) {
            $log = SkillEventLog::create([
                'student_id' => $userId,
                'course_id' => $event['course_id'] ?? null,
                'session_id' => $event['session_id'] ?? null,
                'block_id' => $event['block_id'] ?? null,
                'dimension_indices' => $event['dimension_indices'] ?? null,
                'delta_values' => $event['delta_values'] ?? null,
                'score' => $event['score'],
                'ts_bubble_open' => $event['ts_bubble_open'] ?? null,
                'ts_answer_click' => $event['ts_answer_click'] ?? null,
                'ts_answer_submit' => $event['ts_answer_submit'] ?? null,
                'is_correct' => $event['is_correct'] ?? null,
                'attempt_count' => $event['attempt_count'] ?? 1,
                'help_used' => $event['help_used'] ?? false,
                'time_on_task_ms' => $event['time_on_task_ms'] ?? null,
                'extra_events_json' => $event['extra_events_json'] ?? null,
            ]);

            $results[] = [
                'id' => $log->id,
                'block_id' => $log->block_id,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'inserted' => count($results),
            ],
        ], 201);
    }
}
