<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Course;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin CRUD for chat mentors.
 *
 * The rules that matter are the ones protecting existing conversations: slugs
 * are immutable, a mentor with history cannot be deleted, and a hidden mentor
 * must be bound to a course or nobody could ever reach it.
 */
class MentorAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        return $this;
    }

    private function makeCourse(): Course
    {
        return Course::create([
            'course_id' => 'c-' . uniqid(),
            'name' => 'Matematika 9',
            'data' => [],
        ]);
    }

    public function test_index_lists_every_mentor_with_its_conversation_count(): void
    {
        $user = User::factory()->create();
        ChatSession::create([
            'user_id' => $user->id,
            'title' => '',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/admin/mentors');

        $response->assertStatus(200);
        $rows = collect($response->json('data'))->keyBy('slug');
        $this->assertCount(6, $rows);
        $this->assertSame(1, $rows['ai_teacher']['session_count']);
        $this->assertSame(0, $rows['skolnik']['session_count']);
    }

    public function test_admin_access_is_required(): void
    {
        $this->getJson('/api/admin/mentors')->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->getJson('/api/admin/mentors')->assertStatus(403);
    }

    public function test_creating_an_llm_mentor(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'fyzik',
            'name' => 'Fyzik',
            'subtitle' => 'Pomáhá s fyzikou',
            'emoji' => '🔬',
            'engine' => 'llm',
            'ai_context' => 'Zaměřuj se na fyziku.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'fyzik')
            ->assertJsonPath('data.visibility', 'public');

        $this->assertSame('Zaměřuj se na fyziku.', Mentor::where('slug', 'fyzik')->value('ai_context'));
    }

    public function test_creating_a_rag_mentor_stores_its_legal_entity_and_drops_the_prompt(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'skolnik_zs_b',
            'name' => 'Školník ZŠ B',
            'engine' => 'rag',
            'rag_legal_entity' => 'ZS_B',
            // Sent by accident when flipping the form; must not be persisted.
            'ai_context' => 'zbytek po LLM',
        ]);

        $response->assertStatus(201);

        $mentor = Mentor::where('slug', 'skolnik_zs_b')->first();
        $this->assertSame('ZS_B', $mentor->rag_legal_entity);
        $this->assertNull($mentor->ai_context);
    }

    public function test_switching_a_mentor_from_rag_to_llm_clears_the_legal_entity(): void
    {
        $mentor = Mentor::where('slug', 'skolnik')->first();
        $mentor->update(['rag_legal_entity' => 'ZS_B', 'rag_domains' => ['SECONDARY_ADMISSIONS']]);

        $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", [
            'engine' => 'llm',
            'ai_context' => 'Teď jsem LLM.',
        ])->assertStatus(200);

        $mentor->refresh();
        $this->assertSame('llm', $mentor->engine);
        $this->assertNull($mentor->rag_legal_entity);
        $this->assertNull($mentor->rag_domains);
        $this->assertSame('Teď jsem LLM.', $mentor->ai_context);
    }

    /** BR-DAB49K — the RAG `domains` filter is editable per mentor. */
    public function test_a_rag_mentor_stores_its_domains_without_blanks_or_duplicates(): void
    {
        $mentor = Mentor::where('slug', 'cermatik')->first();

        $response = $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", [
            'rag_domains' => [' SECONDARY_ADMISSIONS ', '', 'SECONDARY_ADMISSIONS', 'SCHOOL_EVENTS'],
        ])->assertStatus(200);

        $this->assertSame(['SECONDARY_ADMISSIONS', 'SCHOOL_EVENTS'], $response->json('data.rag_domains'));
        $this->assertSame(['SECONDARY_ADMISSIONS', 'SCHOOL_EVENTS'], $mentor->fresh()->rag_domains);

        // Emptying the list stores null, so the chat sends no `domains` key.
        $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", [
            'rag_domains' => [],
        ])->assertStatus(200)->assertJsonPath('data.rag_domains', []);
        $this->assertNull($mentor->fresh()->rag_domains);

        $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", [
            'rag_domains' => 'SECONDARY_ADMISSIONS',
        ])->assertStatus(422);
    }

    /** The migration seeds Cermatík's domain so the fix is live on deploy. */
    public function test_cermatik_is_seeded_with_the_secondary_admissions_domain(): void
    {
        $mentor = Mentor::where('slug', 'cermatik')->first();

        $this->assertSame(['SECONDARY_ADMISSIONS'], $mentor->rag_domains);
        $this->assertNull(Mentor::where('slug', 'skolnik')->first()->rag_domains);
    }

    public function test_avatar_url_is_stored_and_must_be_a_url(): void
    {
        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'fyzik',
            'name' => 'Fyzik',
            'engine' => 'llm',
            'avatar_url' => 'https://cdn.edu-ai.eu/mentors/fyzik.png',
        ])->assertStatus(201)
            ->assertJsonPath('data.avatar_url', 'https://cdn.edu-ai.eu/mentors/fyzik.png');

        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'chemik',
            'name' => 'Chemik',
            'engine' => 'llm',
            'avatar_url' => 'not a url',
        ])->assertStatus(422)->assertJsonValidationErrors('avatar_url');
    }

    public function test_a_hidden_mentor_must_be_bound_to_a_course(): void
    {
        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'tajny',
            'name' => 'Tajný',
            'engine' => 'llm',
            'visibility' => 'hidden',
        ])->assertStatus(422)->assertJsonValidationErrors('course_id');

        $course = $this->makeCourse();

        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'tajny',
            'name' => 'Tajný',
            'engine' => 'llm',
            'visibility' => 'hidden',
            'course_id' => $course->id,
        ])->assertStatus(201)->assertJsonPath('data.course_id', $course->id);
    }

    /**
     * A partial update of a mentor that is already bound to a course must not
     * have to resend course_id just to change something else.
     */
    public function test_hidden_mentor_keeps_its_course_on_a_partial_update(): void
    {
        $course = $this->makeCourse();
        $mentor = Mentor::create([
            'slug' => 'tajny',
            'name' => 'Tajný',
            'engine' => 'llm',
            'visibility' => 'hidden',
            'course_id' => $course->id,
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/admin/mentors/{$mentor->id}", ['name' => 'Přejmenovaný'])
            ->assertStatus(200)
            ->assertJsonPath('data.course_id', $course->id);

        $this->assertSame($course->id, $mentor->fresh()->course_id);
    }

    /**
     * Clearing the course of a hidden mentor would leave it unreachable.
     */
    public function test_hidden_mentor_cannot_have_its_course_cleared(): void
    {
        $course = $this->makeCourse();
        $mentor = Mentor::create([
            'slug' => 'tajny',
            'name' => 'Tajný',
            'engine' => 'llm',
            'visibility' => 'hidden',
            'course_id' => $course->id,
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/admin/mentors/{$mentor->id}", ['course_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('course_id');
    }

    public function test_slug_is_unique_and_shaped(): void
    {
        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'ai_teacher',
            'name' => 'Duplicita',
            'engine' => 'llm',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');

        $this->actingAsAdmin()->postJson('/api/admin/mentors', [
            'slug' => 'Velké Písmeno',
            'name' => 'Špatný slug',
            'engine' => 'llm',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    /**
     * chat_sessions.persona stores the slug, so a rename would orphan history.
     */
    public function test_slug_cannot_be_changed_on_update(): void
    {
        $mentor = Mentor::where('slug', 'ai_teacher')->first();

        $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", [
            'slug' => 'jiny_slug',
            'name' => 'Přejmenovaný',
        ])->assertStatus(200);

        $mentor->refresh();
        $this->assertSame('ai_teacher', $mentor->slug);
        $this->assertSame('Přejmenovaný', $mentor->name);
    }

    public function test_a_mentor_with_conversations_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        ChatSession::create([
            'user_id' => $user->id,
            'title' => '',
            'persona' => 'ai_teacher',
            'last_message_at' => now(),
        ]);

        $mentor = Mentor::where('slug', 'ai_teacher')->first();

        $this->actingAsAdmin()->deleteJson("/api/admin/mentors/{$mentor->id}")->assertStatus(409);
        $this->assertDatabaseHas('mentors', ['slug' => 'ai_teacher']);

        // Deactivating is the supported way to retire it.
        $this->actingAsAdmin()->putJson("/api/admin/mentors/{$mentor->id}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_unused_mentor_can_be_deleted(): void
    {
        $mentor = Mentor::where('slug', 'cermatik')->first();

        $this->actingAsAdmin()->deleteJson("/api/admin/mentors/{$mentor->id}")->assertStatus(200);
        $this->assertDatabaseMissing('mentors', ['slug' => 'cermatik']);
    }
}
