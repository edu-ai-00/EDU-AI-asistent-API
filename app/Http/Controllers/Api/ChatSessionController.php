<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Services\ChatAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ChatSession::where('user_id', $request->user()->id);

        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $sessions = $query->orderBy('last_message_at', 'desc')->get();

        return response()->json([
            'data' => $sessions->map(fn (ChatSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'persona' => $s->persona,
                'last_message_at' => $s->last_message_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
                'updated_at' => $s->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'persona' => 'required|string|in:ai_teacher,math_mentor,study_coach,language_mentor',
            'title' => 'nullable|string|max:255',
        ]);

        $session = ChatSession::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? '',
            'persona' => $validated['persona'],
            'last_message_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'title' => $session->title,
                'persona' => $session->persona,
                'last_message_at' => $session->last_message_at->toIso8601String(),
                'created_at' => $session->created_at->toIso8601String(),
                'updated_at' => $session->updated_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Re-generate the session title from conversation content.
     *
     * POST /api/chat/sessions/{id}/generate-title
     */
    public function generateTitle(Request $request, int $id, ChatAiService $ai): JsonResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Collect user messages to build context for title
        $userMessages = $session->messages()
            ->where('role', 'user')
            ->orderBy('created_at', 'asc')
            ->limit(5)
            ->pluck('content')
            ->implode("\n");

        if (empty($userMessages)) {
            return response()->json(['message' => 'No messages to generate title from'], 422);
        }

        $title = $ai->generateTitle($userMessages);

        if ($title) {
            $session->update(['title' => $title]);
        }

        return response()->json([
            'data' => [
                'id' => $session->id,
                'title' => $session->title,
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $session->delete();

        return response()->json(['message' => 'Session deleted']);
    }
}
