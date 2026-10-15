<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuizFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI comment for the end-of-quiz feedback popup ("Quiz Feedback v1").
 *
 * Stateless like /answers/evaluate: the app sends the finished block's
 * results, the comment comes back, nothing is stored. The popup treats a 503
 * the same as being offline — the section simply does not render.
 */
class QuizFeedbackController extends Controller
{
    /**
     * POST /api/quiz/feedback
     */
    public function store(Request $request, QuizFeedbackService $service): JsonResponse
    {
        $validated = $request->validate([
            // Ten per block by design; a little headroom for blocks that
            // carry a bonus question, and a hard lid on token cost.
            'tasks' => 'required|array|min:1|max:15',
            'tasks.*.assignment' => 'nullable|string|max:2000',
            'tasks.*.outcome' => 'required|string|in:correct_no_hint,correct_with_hint,wrong,unanswered,gamed',
            'tasks.*.hint_used' => 'sometimes|boolean',
            'tasks.*.help_used' => 'sometimes|boolean',
            'tasks.*.duration_ms' => 'nullable|integer|min:0',
            'domain_deltas' => 'sometimes|array|max:10',
            'domain_deltas.*' => 'numeric',
            'language' => 'sometimes|string|max:8',
        ]);

        $comment = $service->comment(
            $validated['tasks'],
            $validated['domain_deltas'] ?? [],
            $validated['language'] ?? $request->getPreferredLanguage(['cs', 'sk', 'en']),
        );

        if ($comment === null) {
            return response()->json(['message' => 'AI feedback unavailable'], 503);
        }

        return response()->json(['data' => ['comment' => $comment]]);
    }
}
