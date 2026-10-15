<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatMessageController;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-C7TYTT — the lesson mentor sheet sends the task context alongside the
 * student's question instead of as the question itself.
 *
 * The context is stored as a `message_type: context` user message: the model
 * still reads it (the stream loads every prior message), but the client filters
 * it out of the transcript, so the conversation opens with what the student
 * actually typed.
 */
class ChatMessageContextTest extends TestCase
{
    use RefreshDatabase;

    private function mentorSession(User $user): ChatSession
    {
        return ChatSession::create([
            'user_id' => $user->id,
            'persona' => 'math_mentor',
            'title' => '',
        ]);
    }

    public function test_context_is_stored_as_a_hidden_message_before_the_question(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $session = $this->mentorSession($user);

        $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Proč se to dělí dvěma?',
            'context' => 'Obsah úlohy: Vypočítej obsah trojúhelníku.',
        ])->assertStatus(201);

        $messages = ChatMessage::where('chat_session_id', $session->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $messages);

        // Context first, so the model reads the task before the question.
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame(ChatMessageController::TYPE_CONTEXT, $messages[0]->message_type);
        $this->assertStringContainsString('trojúhelníku', $messages[0]->content);

        $this->assertSame('user', $messages[1]->role);
        $this->assertSame('text', $messages[1]->message_type);
        $this->assertSame('Proč se to dělí dvěma?', $messages[1]->content);

        $this->assertSame('assistant', $messages[2]->role);
        $this->assertSame('', $messages[2]->content);
    }

    public function test_the_response_returns_the_question_not_the_context(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $session = $this->mentorSession($user);

        $response = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Proč se to dělí dvěma?',
            'context' => 'Obsah úlohy: Vypočítej obsah trojúhelníku.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user_message.content', 'Proč se to dělí dvěma?')
            ->assertJsonPath('data.user_message.message_type', 'text');
    }

    public function test_a_blank_context_stores_nothing_extra(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $session = $this->mentorSession($user);

        $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Ahoj',
            'context' => '   ',
        ])->assertStatus(201);

        $this->assertSame(
            0,
            ChatMessage::where('chat_session_id', $session->id)
                ->where('message_type', ChatMessageController::TYPE_CONTEXT)
                ->count(),
        );
    }

    public function test_sending_without_a_context_is_unchanged(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $session = $this->mentorSession($user);

        $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Ahoj',
        ])->assertStatus(201);

        $messages = ChatMessage::where('chat_session_id', $session->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertSame('Ahoj', $messages[0]->content);
    }
}
