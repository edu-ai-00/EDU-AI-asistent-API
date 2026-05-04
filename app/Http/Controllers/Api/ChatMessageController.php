<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\ChatAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMessageController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $query = $session->messages()->orderBy('created_at', 'asc');

        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $messages = $query->get();

        return response()->json([
            'data' => $messages->map(fn (ChatMessage $m) => $this->formatMessage($m)),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'content' => 'required|string|max:4000',
        ]);

        $userMessage = $session->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
            'message_type' => 'text',
        ]);

        $assistantMessage = $session->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'message_type' => 'text',
        ]);

        $session->update(['last_message_at' => now()]);

        // Auto-generate title from first user message
        if ($session->title === '' || $session->title === null) {
            try {
                $ai = app(ChatAiService::class);
                $title = $ai->generateTitle($validated['content']);
                if ($title) {
                    $session->update(['title' => $title]);
                }
            } catch (\Throwable $e) {
                // Non-critical — keep empty title
            }
        }

        return response()->json([
            'data' => [
                'user_message' => $this->formatMessage($userMessage),
                'assistant_message' => $this->formatMessage($assistantMessage),
            ],
        ], 201);
    }

    public function stream(Request $request, int $id, ChatAiService $ai): StreamedResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $messageId = $request->query('message_id');
        $assistantMessage = ChatMessage::where('id', $messageId)
            ->where('chat_session_id', $session->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        return response()->stream(function () use ($session, $assistantMessage, $ai, $request) {
            // Reconnection: if message already has content, return it
            if ($assistantMessage->content !== '') {
                echo "data: " . json_encode(['token' => $assistantMessage->content]) . "\n\n";
                ob_flush();
                flush();
                echo "data: [DONE]\n\n";
                ob_flush();
                flush();
                return;
            }

            // Load prior messages (before the assistant placeholder)
            $history = $session->messages()
                ->where('id', '<', $assistantMessage->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn (ChatMessage $m) => [
                    'role' => $m->role,
                    'content' => $m->content,
                ])
                ->toArray();

            // Build system prompt and prepend
            $systemPrompt = $ai->buildSystemPrompt($request->user(), $session->persona);
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $history,
            );

            // Truncate to fit context window
            $messages = $ai->truncateMessages($messages);

            // Stream from OpenRouter
            $fullContent = '';
            try {
                foreach ($ai->streamResponse($messages) as $token) {
                    $fullContent .= $token;
                    echo "data: " . json_encode(['token' => $token]) . "\n\n";
                    ob_flush();
                    flush();
                }
            } catch (\Throwable $e) {
                echo "data: " . json_encode(['error' => 'AI generation failed']) . "\n\n";
                ob_flush();
                flush();
            }

            // Save whatever was generated
            $assistantMessage->update(['content' => $fullContent]);

            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function allMessages(Request $request): JsonResponse
    {
        $sessionIds = ChatSession::where('user_id', $request->user()->id)->pluck('id');

        $query = ChatMessage::whereIn('chat_session_id', $sessionIds);

        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $messages = $query->orderBy('created_at', 'asc')->get();

        return response()->json([
            'data' => $messages->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'chat_session_id' => $m->chat_session_id,
                'role' => $m->role,
                'content' => $m->content,
                'message_type' => $m->message_type,
                'feedback_type' => $m->feedback_type,
                'created_at' => $m->created_at->toIso8601String(),
                'updated_at' => $m->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function updateFeedback(Request $request, int $id): JsonResponse
    {
        $message = ChatMessage::findOrFail($id);

        // Verify ownership through session
        $session = ChatSession::where('id', $message->chat_session_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'feedback_type' => 'nullable|string|in:like,dislike',
            'feedback_detail' => 'nullable|string|max:2000',
        ]);

        $message->update($validated);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'feedback_type' => $message->feedback_type,
                'feedback_detail' => $message->feedback_detail,
                'updated_at' => $message->updated_at->toIso8601String(),
            ],
        ]);
    }

    private function formatMessage(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'message_type' => $m->message_type,
            'metadata' => $m->metadata,
            'feedback_type' => $m->feedback_type,
            'feedback_detail' => $m->feedback_detail,
            'created_at' => $m->created_at->toIso8601String(),
            'updated_at' => $m->updated_at->toIso8601String(),
        ];
    }
}
