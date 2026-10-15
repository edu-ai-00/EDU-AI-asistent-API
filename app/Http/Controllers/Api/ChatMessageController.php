<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\ChatAiService;
use App\Services\RagSearchException;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMessageController extends Controller
{
    /** Hidden task context: fed to the model, never rendered as a bubble. */
    public const TYPE_CONTEXT = 'context';

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
            'context' => 'nullable|string|max:8000',
        ]);

        // Task context sent alongside the question (lesson mentor sheet). It is
        // a user-role message so the model reads it as the student's situation,
        // but `message_type: context` keeps it out of the transcript the client
        // renders — previously the whole blob was the visible first message.
        $context = trim((string) ($validated['context'] ?? ''));
        if ($context !== '') {
            $session->messages()->create([
                'role' => 'user',
                'content' => $context,
                'message_type' => self::TYPE_CONTEXT,
            ]);
        }

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

        // Auto-generate title from the first user message. Deferred so the LLM
        // round-trip runs *after* the response is flushed — this POST must stay
        // fast. A slow response here was the trigger for duplicate messages
        // (BR-NT8CEC): the client timed out waiting on the title generation and
        // its retry interceptor resent the (non-idempotent) POST, so the server
        // created a second user+assistant pair. The app already reads the
        // generated title on its next sync, so nothing depends on it inline.
        if ($session->title === '' || $session->title === null) {
            $content = $validated['content'];
            $sessionId = $session->id;
            defer(function () use ($content, $sessionId) {
                try {
                    $title = app(ChatAiService::class)->generateTitle($content);
                    if ($title) {
                        ChatSession::whereKey($sessionId)->update(['title' => $title]);
                    }
                } catch (\Throwable $e) {
                    // Non-critical — keep empty title
                }
            });
        }

        return response()->json([
            'data' => [
                'user_message' => $this->formatMessage($userMessage),
                'assistant_message' => $this->formatMessage($assistantMessage),
            ],
        ], 201);
    }

    public function stream(Request $request, int $id, ChatAiService $ai, RagSearchService $rag): StreamedResponse
    {
        $session = ChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $messageId = $request->query('message_id');
        $assistantMessage = ChatMessage::where('id', $messageId)
            ->where('chat_session_id', $session->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        return response()->stream(function () use ($session, $assistantMessage, $ai, $rag, $request) {
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

            // RAG personas: the search service returns the whole answer at
            // once (3-30 s), so emit it as a single token. answer() persists
            // content + metadata on the placeholder itself; on failure the
            // content stays empty so a reconnect retries instead of replaying.
            if (RagSearchService::isRagPersona($session->persona)) {
                try {
                    $text = $rag->answer($request->user(), $session, $assistantMessage);
                    echo "data: " . json_encode(['token' => $text]) . "\n\n";

                    // Follow-up questions ride in their own frame so the app can
                    // render them as buttons (BR-UE8KK4). formatAnswer() still
                    // writes them into the markdown for builds that only read
                    // `token`; those builds ignore this frame.
                    $suggestions = $assistantMessage->metadata['suggestions'] ?? [];
                    if (is_array($suggestions) && $suggestions !== []) {
                        echo "data: " . json_encode(['suggestions' => $suggestions]) . "\n\n";
                    }
                } catch (\Throwable $e) {
                    $reason = $e instanceof RagSearchException ? $e->reason : 'unexpected';
                    Log::error('RAG chat answer failed', [
                        'session_id' => $session->id,
                        'message_id' => $assistantMessage->id,
                        'reason' => $reason,
                        'error' => $e->getMessage(),
                    ]);
                    // Keep content empty (so reconnect retries) but record why
                    // it failed where the admin chat view can see it.
                    $assistantMessage->update([
                        'metadata' => [
                            'source' => 'rag',
                            'error' => $reason,
                            'error_detail' => mb_substr($e->getMessage(), 0, 500),
                        ],
                    ]);
                    echo "data: " . json_encode(['error' => 'AI generation failed', 'reason' => $reason]) . "\n\n";
                }
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
                    // Media links are stripped on the way to the model only —
                    // the stored message keeps its original text (BR-KFHPAR).
                    'content' => $m->role === 'user'
                        ? $ai->sanitizeUserContent($m->content)
                        : $m->content,
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
                // Carries the RAG answer's `suggestions` and `sources`, which
                // the app renders as follow-up buttons (BR-UE8KK4).
                'metadata' => $m->metadata,
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
