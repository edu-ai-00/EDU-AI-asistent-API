<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    /**
     * Save a quiz attempt.
     *
     * POST /api/user/quiz-attempts
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|string|max:255',
            'total_questions' => 'required|integer|min:1',
            'correct_answers' => 'required|integer|min:0',
            'score_percent' => 'required|integer|min:0|max:100',
            'time_spent_seconds' => 'sometimes|integer|min:0',
            'started_at' => 'sometimes|nullable|date',
            'completed_at' => 'sometimes|nullable|date',
            'answers' => 'sometimes|array',
            'answers.*.question_index' => 'required_with:answers|integer',
            'answers.*.question_id' => 'sometimes|string',
            'answers.*.selected_answer' => 'sometimes|nullable|string',
            'answers.*.is_correct' => 'sometimes|boolean',
            'answers.*.answered_at' => 'sometimes|nullable|date',
        ]);

        $userId = $request->user()->id;
        $courseId = $validated['course_id'];
        // Normalize so lookup matches the cast datetime in DB regardless of
        // input timezone or fractional precision sent by the client.
        $startedAt = isset($validated['started_at'])
            ? Carbon::parse($validated['started_at'])->utc()
            : null;

        // Idempotent on (user_id, course_id, started_at). Same logical attempt
        // (rapid taps, retries, hot reloads) collapses onto a single row.
        if ($startedAt !== null) {
            $attempt = QuizAttempt::updateOrCreate(
                [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'started_at' => $startedAt,
                ],
                array_merge($validated, [
                    'user_id' => $userId,
                    'started_at' => $startedAt,
                ])
            );
            $created = $attempt->wasRecentlyCreated;
        } else {
            // Legacy clients without started_at — fall back to plain insert.
            $attempt = QuizAttempt::create(['user_id' => $userId, ...$validated]);
            $created = true;
        }

        return response()->json([
            'id' => $attempt->id,
            'status' => $created ? 'saved' : 'updated',
        ], $created ? 201 : 200);
    }

    /**
     * Get quiz attempts for the authenticated user.
     * Supports ?course_id= filter.
     *
     * GET /api/user/quiz-attempts
     */
    public function index(Request $request): JsonResponse
    {
        $query = QuizAttempt::where('user_id', $request->user()->id);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $attempts = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $attempts->map(fn ($a) => $this->formatAttempt($a)),
        ]);
    }

    private function formatAttempt(QuizAttempt $a): array
    {
        $answers = $a->answers;
        if (is_string($answers)) {
            $answers = json_decode($answers, true) ?? [];
        }

        return [
            'id' => $a->id,
            'course_id' => $a->course_id,
            'total_questions' => $a->total_questions,
            'correct_answers' => $a->correct_answers,
            'score_percent' => $a->score_percent,
            'time_spent_seconds' => $a->time_spent_seconds,
            'started_at' => $a->started_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'answers' => $answers ?? [],
            'created_at' => $a->created_at->toIso8601String(),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Admin endpoints
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * List quiz attempts for a course across all users (admin).
     *
     * GET /api/admin/quiz-attempts/{courseId}
     */
    public function adminByCourse(string $courseId): JsonResponse
    {
        $attempts = QuizAttempt::with('user:id,name,email,classroom_id')
            ->where('course_id', $courseId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'course_id' => $courseId,
            'data' => $attempts->map(function ($a) {
                $base = $this->formatAttempt($a);
                $base['user'] = $a->user ? [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                    'email' => $a->user->email,
                    'classroom_id' => $a->user->classroom_id,
                ] : null;
                return $base;
            }),
            'meta' => [
                'total_attempts' => $attempts->count(),
                'unique_users' => $attempts->pluck('user_id')->unique()->count(),
                'avg_score' => $attempts->count() > 0 ? round($attempts->avg('score_percent'), 1) : null,
            ],
        ]);
    }

    /**
     * List quiz attempts for a specific user (admin).
     *
     * GET /api/admin/quiz-attempts/user/{userId}
     */
    public function adminByUser(Request $request, int $userId): JsonResponse
    {
        $query = QuizAttempt::where('user_id', $userId);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $attempts = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $attempts->map(fn ($a) => $this->formatAttempt($a)),
            'meta' => [
                'total_attempts' => $attempts->count(),
            ],
        ]);
    }
}
