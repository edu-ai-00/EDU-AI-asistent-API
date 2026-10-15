<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin chat list — sessions the student opened but never wrote into are
 * noise, not history (BR-MWEUHX).
 */
class AdminChatSessionListTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        config(['admin.api_key' => 'test-admin-key']);

        return ['X-Admin-Key' => 'test-admin-key'];
    }

    private function makeSession(User $user, string $title): ChatSession
    {
        return ChatSession::create([
            'user_id' => $user->id,
            'title' => $title,
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);
    }

    public function test_sessions_without_any_message_are_hidden_from_the_list(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $written = $this->makeSession($student, 'Zlomky');
        $written->messages()->create([
            'role' => 'user',
            'content' => 'kolik je 3/4 + 1/2',
            'message_type' => 'text',
        ]);

        $empty = $this->makeSession($student, '');

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/chat/sessions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($written->id, $ids);
        $this->assertNotContains($empty->id, $ids);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_session_with_only_a_teacher_reply_stays_listed(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $session = $this->makeSession($student, '');
        $session->messages()->create([
            'role' => 'assistant',
            'content' => 'Ahoj, s čím ti mohu pomoci?',
            'message_type' => 'text',
            'metadata' => ['sent_by' => 'teacher'],
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/chat/sessions');

        $response->assertOk();
        $this->assertContains($session->id, collect($response->json('data'))->pluck('id')->all());
    }
}
