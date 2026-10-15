<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\Mentor;
use App\Services\ChatAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            // Mentors come from the `mentors` table now; an unknown or retired
            // slug is still a 422, same as when this was an in: rule.
            'persona' => [
                'required',
                'string',
                'max:50',
                Rule::exists('mentors', 'slug')->where('is_active', true),
            ],
            'title' => 'nullable|string|max:255',
        ]);

        // Hidden mentors belong to one course; only students of that course may
        // open a chat with them, so the check is here and not only in the list.
        $mentor = Mentor::selectableBy($request->user())
            ->where('slug', $validated['persona'])
            ->first();

        if (! $mentor) {
            return response()->json(['message' => 'Tento mentor není dostupný'], 403);
        }

        $session = ChatSession::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? '',
            'persona' => $mentor->slug,
            'last_message_at' => now(),
            'rag_conversation_id' => $mentor->isRag() ? (string) Str::uuid() : null,
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
