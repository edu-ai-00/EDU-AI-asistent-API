<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-4KPUJQ: a teacher must not read their pupils' chats — the client wants
 * Chaty gone from the teacher's administration, admins keep seeing all of them.
 * Teaching the pupil's class is not enough; only a mentor may, and no teacher
 * can be one yet.
 */
class TeacherChatAccessTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $pupil;

    private ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classroom = Classroom::create(['name' => '7.A']);
        $this->pupil = User::factory()->inClassroom($this->classroom)->create(['role' => 'student']);

        $this->session = ChatSession::create([
            'user_id' => $this->pupil->id,
            'title' => 'Zlomky',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);
        $this->session->messages()->create([
            'role' => 'user',
            'content' => 'kolik je 3/4 + 1/2',
            'message_type' => 'text',
        ]);
    }

    private function teacherToken(): string
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->classroom->teachers()->attach($teacher->id);

        return $teacher->createToken('t')->plainTextToken;
    }

    private function adminToken(): string
    {
        return User::factory()->create(['role' => 'admin'])->createToken('a')->plainTextToken;
    }

    public function test_a_teacher_cannot_list_their_pupils_chats(): void
    {
        $this->withToken($this->teacherToken())
            ->getJson('/api/admin/chat/sessions')
            ->assertStatus(403);
    }

    public function test_a_teacher_cannot_open_their_pupils_chat(): void
    {
        $this->withToken($this->teacherToken())
            ->getJson("/api/admin/chat/sessions/{$this->session->id}")
            ->assertStatus(403);
    }

    public function test_a_teacher_cannot_reply_into_their_pupils_chat(): void
    {
        $this->withToken($this->teacherToken())
            ->postJson("/api/admin/chat/sessions/{$this->session->id}/reply", ['content' => 'Ahoj'])
            ->assertStatus(403);

        $this->assertSame(1, $this->session->messages()->count());
    }

    public function test_an_admin_still_sees_and_opens_every_chat(): void
    {
        $token = $this->adminToken();

        $list = $this->withToken($token)->getJson('/api/admin/chat/sessions');
        $list->assertOk();
        $this->assertContains($this->session->id, collect($list->json('data'))->pluck('id')->all());

        $this->withToken($token)
            ->getJson("/api/admin/chat/sessions/{$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'kolik je 3/4 + 1/2');
    }
}
