<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chat transcripts come back in the order they were written (BR-9M9BKT).
 *
 * Eloquent writes timestamps through `Grammar::getDateFormat()`, which is
 * 'Y-m-d H:i:s' — whole seconds, whatever precision the column holds. One
 * question posts the user message and the empty assistant placeholder in the
 * same request, so the pair always shares a `created_at` to the second, and
 * ordering by that column alone is not a total order: Postgres returns tied
 * rows in physical order. Filling the placeholder rewrites that row, and on a
 * table where vacuum has freed earlier slots the new tuple can land below the
 * question it answers — which is how the teacher came to read the answer
 * first. `id` is the write order, so it is what breaks the tie.
 *
 * These tests pin the contract on any engine; the physical reordering itself
 * only reproduces on a vacuumed Postgres table, so it is not staged here.
 */
class ChatMessageOrderTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        config(['admin.api_key' => 'test-admin-key']);

        return ['X-Admin-Key' => 'test-admin-key'];
    }

    /**
     * Replay one question the way ChatMessageController::store() writes it:
     * the user message and an empty assistant placeholder in the same second,
     * the answer streamed into the placeholder afterwards.
     *
     * @return array{0: ChatMessage, 1: ChatMessage}
     */
    private function askAndAnswer(ChatSession $session, string $question, string $answer, string $at): array
    {
        $user = $session->messages()->create([
            'role' => 'user',
            'content' => $question,
            'message_type' => 'text',
        ]);

        $assistant = $session->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'message_type' => 'text',
        ]);

        // Both rows carry the one second the request was served in.
        ChatMessage::whereKey([$user->id, $assistant->id])
            ->update(['created_at' => $at, 'updated_at' => $at]);

        // The stream fills the placeholder.
        $assistant->update(['content' => $answer]);

        return [$user->fresh(), $assistant->fresh()];
    }

    public function test_admin_transcript_keeps_each_answer_after_its_question(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $session = ChatSession::create([
            'user_id' => $student->id,
            'title' => 'Procenta',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);

        // Three turns inside the same minute, as in the reported conversation.
        [$q1, $a1] = $this->askAndAnswer($session, 'mas mě rad', 'Jsem AI, takže to nemám jako kamarádi.', '2026-10-20 10:31:04');
        [$q2, $a2] = $this->askAndAnswer($session, 'nejsi ai pro mě jsi kámoš', 'To mě těší, být tvůj učící kámoš je fajn.', '2026-10-20 10:31:21');
        [$q3, $a3] = $this->askAndAnswer($session, 'je mi tě lito', 'To je od tebe hezké, děkuju.', '2026-10-20 10:31:38');

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/chat/sessions/{$session->id}");

        $response->assertOk();

        $this->assertSame(
            [$q1->id, $a1->id, $q2->id, $a2->id, $q3->id, $a3->id],
            collect($response->json('data.messages'))->pluck('id')->all(),
        );
    }

    public function test_student_transcript_keeps_each_answer_after_its_question(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $session = ChatSession::create([
            'user_id' => $student->id,
            'title' => 'Procenta',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);

        [$q1, $a1] = $this->askAndAnswer($session, 'kolik je 10 % z 80', 'Osm.', '2026-10-20 10:31:04');
        [$q2, $a2] = $this->askAndAnswer($session, 'a 30 %?', 'Třikrát tolik, tedy 24.', '2026-10-20 10:31:21');

        $response = $this->actingAs($student)
            ->getJson("/api/chat/sessions/{$session->id}/messages");

        $response->assertOk();

        $this->assertSame(
            [$q1->id, $a1->id, $q2->id, $a2->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_all_messages_sync_keeps_each_answer_after_its_question(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $session = ChatSession::create([
            'user_id' => $student->id,
            'title' => 'Procenta',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);

        [$q1, $a1] = $this->askAndAnswer($session, 'kolik je 10 % z 80', 'Osm.', '2026-10-20 10:31:04');
        [$q2, $a2] = $this->askAndAnswer($session, 'a 30 %?', 'Třikrát tolik, tedy 24.', '2026-10-20 10:31:21');

        $response = $this->actingAs($student)->getJson('/api/chat/messages');

        $response->assertOk();

        $this->assertSame(
            [$q1->id, $a1->id, $q2->id, $a2->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }
}
