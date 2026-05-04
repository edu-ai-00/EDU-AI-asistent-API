<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PracticeReviewLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeReviewLogController extends Controller
{
    /**
     * Get review logs for the authenticated user.
     * Supports ?since=ISO8601 filter, limited to 500 records.
     *
     * GET /api/user/review-logs
     */
    public function index(Request $request): JsonResponse
    {
        $query = PracticeReviewLog::where('user_id', $request->user()->id);

        if ($request->filled('since')) {
            $query->where('reviewed_at', '>', $request->input('since'));
        }

        $logs = $query->orderBy('reviewed_at', 'desc')->limit(500)->get();

        return response()->json([
            'data' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'card_id' => $l->card_id,
                'user_id' => $l->user_id,
                'rating' => $l->rating,
                'shown_at' => $l->shown_at?->toIso8601String(),
                'reviewed_at' => $l->reviewed_at?->toIso8601String(),
                'response_time_sec' => $l->response_time_sec,
                'repetition_number' => $l->repetition_number,
                'stability_after' => $l->stability_after,
                'difficulty_after' => $l->difficulty_after,
                'next_due_date' => $l->next_due_date?->toIso8601String(),
                'interval_days' => $l->interval_days,
                'user_feedback' => $l->user_feedback,
                'created_at' => $l->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Batch create review logs (for offline sync).
     *
     * POST /api/user/review-logs
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logs' => 'required|array|min:1',
            'logs.*.card_id' => 'required|integer|exists:practice_cards,id',
            'logs.*.rating' => 'required|integer|min:1|max:4',
            'logs.*.shown_at' => 'required|date',
            'logs.*.reviewed_at' => 'required|date',
            'logs.*.response_time_sec' => 'required|integer|min:0',
            'logs.*.repetition_number' => 'required|integer|min:0',
            'logs.*.stability_after' => 'required|numeric',
            'logs.*.difficulty_after' => 'required|numeric',
            'logs.*.next_due_date' => 'required|date',
            'logs.*.interval_days' => 'required|integer',
            'logs.*.user_feedback' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['logs'] as $entry) {
            $log = PracticeReviewLog::create([
                'card_id' => $entry['card_id'],
                'user_id' => $userId,
                'rating' => $entry['rating'],
                'shown_at' => $entry['shown_at'],
                'reviewed_at' => $entry['reviewed_at'],
                'response_time_sec' => $entry['response_time_sec'],
                'repetition_number' => $entry['repetition_number'],
                'stability_after' => $entry['stability_after'],
                'difficulty_after' => $entry['difficulty_after'],
                'next_due_date' => $entry['next_due_date'],
                'interval_days' => $entry['interval_days'],
                'user_feedback' => $entry['user_feedback'] ?? null,
            ]);

            $results[] = [
                'id' => $log->id,
                'card_id' => $log->card_id,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $results,
        ], 201);
    }
}
