<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChatController extends Controller
{
    /**
     * List all chat sessions.
     *
     * GET /api/admin/chat/sessions (admin-only, BR-4KPUJQ)
     * Filters: ?user_id=, ?persona=, ?search= (title), ?classroom_id=
     */
    public function index(Request $request): JsonResponse
    {
        // The app opens a session the moment a mentor is picked, so a student
        // who backs out without typing leaves a row with no messages. Those
        // are not conversations — keep them out of the history (BR-MWEUHX).
        $query = ChatSession::with('user:id,name,email')
            ->has('messages');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('persona')) {
            $query->where('persona', $request->input('persona'));
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('classroom_id')) {
            $studentIds = \App\Models\User::inClassrooms([$request->input('classroom_id')])->pluck('id');
            $query->whereIn('user_id', $studentIds);
        }

        $sessions = $query->orderBy('last_message_at', 'desc')->paginate(50);

        return response()->json([
            'data' => collect($sessions->items())->map(fn (ChatSession $s) => [
                'id' => $s->id,
                'user' => $s->user ? [
                    'id' => $s->user->id,
                    'name' => $s->user->name,
                    'email' => $s->user->email,
                ] : null,
                'title' => $s->title,
                'persona' => $s->persona,
                'message_count' => $s->messages()->count(),
                'last_message_at' => $s->last_message_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
                'updated_at' => $s->updated_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Show a single chat session with all messages.
     *
     * GET /api/admin/chat/sessions/{id} (admin-only, BR-4KPUJQ)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = ChatSession::with('user:id,name,email')->findOrFail($id);

        $messages = $session->messages()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'data' => [
                'id' => $session->id,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'name' => $session->user->name,
                    'email' => $session->user->email,
                ] : null,
                'title' => $session->title,
                'persona' => $session->persona,
                'last_message_at' => $session->last_message_at?->toIso8601String(),
                'created_at' => $session->created_at->toIso8601String(),
                'updated_at' => $session->updated_at->toIso8601String(),
                'messages' => $messages->map(fn (ChatMessage $m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'message_type' => $m->message_type,
                    'metadata' => $m->metadata,
                    'feedback_type' => $m->feedback_type,
                    'feedback_detail' => $m->feedback_detail,
                    'created_at' => $m->created_at->toIso8601String(),
                    'updated_at' => $m->updated_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * Send a teacher reply into a student's chat session.
     * Creates a message with role=assistant (appears as AI/teacher to student).
     *
     * POST /api/admin/chat/sessions/{id}/reply (admin-only, BR-4KPUJQ)
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $session = ChatSession::findOrFail($id);

        $validated = $request->validate([
            'content' => 'required|string|max:4000',
        ]);

        $message = $session->messages()->create([
            'role' => 'assistant',
            'content' => $validated['content'],
            'message_type' => 'text',
            'metadata' => [
                'sent_by' => 'teacher',
                'teacher_id' => $request->user()?->id,
                'teacher_name' => $request->user()?->name,
            ],
        ]);

        $session->update(['last_message_at' => now()]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'message_type' => $message->message_type,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at->toIso8601String(),
                'updated_at' => $message->updated_at->toIso8601String(),
            ],
        ], 201);
    }
}
