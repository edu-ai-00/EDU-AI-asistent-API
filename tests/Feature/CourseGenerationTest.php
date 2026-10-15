<?php

namespace Tests\Feature;

use App\Models\GenerationSession;
use App\Models\User;
use App\Services\CourseGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI Course Assistant (BR-D4PE8R).
 *
 * The interesting surface here is the PUBLIC capture endpoint: it takes file
 * uploads from an unauthenticated phone, so the token scoping, the expiry and
 * the upload limits are the whole security story.
 */
class CourseGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Create a session through the API and return its plaintext token.
     */
    private function issueToken(): string
    {
        return $this->postJson('/api/admin/generation/sessions', [
            'course_id' => 'MAT_7',
            'course_name' => 'Matematika 7',
        ])->assertCreated()->json('data.token');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Session lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_issue_a_session_and_the_token_is_stored_hashed(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/admin/generation/sessions', [
            'course_id' => 'MAT_7',
            'course_name' => 'Matematika 7',
        ])->assertCreated();

        $token = $response->json('data.token');

        $this->assertNotEmpty($token);
        $this->assertStringContainsString("/capture/{$token}", $response->json('data.capture_url'));
        $this->assertSame('collecting', $response->json('data.status'));

        // The plaintext token must never touch the database.
        $this->assertDatabaseMissing('generation_sessions', ['token_hash' => $token]);
        $this->assertDatabaseHas('generation_sessions', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_missing_capture_base_url_fails_loudly(): void
    {
        $this->actAsAdmin();

        // A QR code that scans fine and opens nothing is undiagnosable from the
        // phone, so an unset ADMIN_APP_URL must break the request, not the link.
        config(['generation.capture_base_url' => null]);

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);

        $this->postJson('/api/admin/generation/sessions');
    }

    public function test_issuing_a_session_requires_admin(): void
    {
        // AdminMiddleware answers 403 for both anonymous and non-admin callers.
        $this->postJson('/api/admin/generation/sessions')->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/admin/generation/sessions')->assertStatus(403);

        $this->assertDatabaseCount('generation_sessions', 0);
    }

    public function test_closing_a_session_deletes_it_and_its_uploads(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('page.jpg', 8, 'image/jpeg')],
        ])->assertCreated();

        $path = GenerationSession::findByToken($token)->attachments[0]['path'];
        Storage::disk('s3')->assertExists($path);

        $this->deleteJson("/api/admin/generation/sessions/{$token}")->assertOk();

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseCount('generation_sessions', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public phone capture
    // ─────────────────────────────────────────────────────────────────────────

    public function test_phone_can_read_session_info_and_upload_without_authentication(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        // Drop the admin identity — the phone is a stranger.
        app('auth')->forgetGuards();

        $this->getJson("/api/generation/capture/{$token}")
            ->assertOk()
            ->assertJsonPath('data.course_name', 'Matematika 7')
            ->assertJsonPath('data.uploaded', 0);

        $this->postJson("/api/generation/capture/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('photo.jpg', 8, 'image/jpeg')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.uploaded', 1)
            // The phone must not learn R2 paths or the course id.
            ->assertJsonMissingPath('data.attachments')
            ->assertJsonMissingPath('data.course_id');

        $this->assertCount(1, GenerationSession::findByToken($token)->attachments);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->getJson('/api/generation/capture/nope-not-a-real-token')->assertNotFound();

        $this->postJson('/api/generation/capture/nope-not-a-real-token/attachments', [
            'files' => [UploadedFile::fake()->create('photo.jpg', 8, 'image/jpeg')],
        ])->assertNotFound();
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        $this->travelTo(Carbon::now()->addMinutes(config('generation.ttl_minutes') + 1));

        $this->getJson("/api/generation/capture/{$token}")->assertNotFound();
        $this->assertNull(GenerationSession::findByToken($token));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Upload limits
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unsupported_file_type_is_rejected(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ])->assertStatus(422);

        $this->assertSame([], GenerationSession::findByToken($token)->attachments);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        config(['generation.max_file_bytes' => 1024]);

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('huge.pdf', 50, 'application/pdf')],
        ])->assertStatus(422);

        $this->assertSame([], GenerationSession::findByToken($token)->attachments);
    }

    public function test_attachment_count_is_capped_across_uploads(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        config(['generation.max_attachments' => 2]);

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [
                UploadedFile::fake()->create('a.jpg', 4, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 4, 'image/jpeg'),
            ],
        ])->assertCreated();

        // The cap counts what is already stored, not just this request.
        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('c.jpg', 4, 'image/jpeg')],
        ])->assertStatus(422);

        $this->assertCount(2, GenerationSession::findByToken($token)->attachments);
    }

    public function test_admin_can_remove_a_single_attachment(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [
                UploadedFile::fake()->create('a.jpg', 4, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 4, 'image/jpeg'),
            ],
        ])->assertCreated();

        $path = GenerationSession::findByToken($token)->attachments[0]['path'];

        $this->deleteJson("/api/admin/generation/sessions/{$token}/attachments/0")
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments');

        Storage::disk('s3')->assertMissing($path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pruning
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pruning_removes_expired_sessions_and_their_files(): void
    {
        $this->actAsAdmin();
        $token = $this->issueToken();

        $this->postJson("/api/admin/generation/sessions/{$token}/attachments", [
            'files' => [UploadedFile::fake()->create('page.pdf', 8, 'application/pdf')],
        ])->assertCreated();

        $path = GenerationSession::findByToken($token)->attachments[0]['path'];

        $this->travelTo(Carbon::now()->addHours(2));

        $this->artisan('generation:prune')->assertSuccessful();

        $this->assertDatabaseCount('generation_sessions', 0);
        Storage::disk('s3')->assertMissing($path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Model output normalisation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_draft_is_normalised_from_messy_model_output(): void
    {
        $service = new CourseGenerationService();

        $decoded = $service->decodeDraft(<<<'JSON'
        ```json
        {
          "lessons": [
            {
              "name": "Zlomky",
              "blocks": [
                {
                  "type": "display",
                  "steps": [
                    {"type": "text", "content": "Zlomek je část celku."},
                    {"type": "text", "content": "   "}
                  ],
                  "difficulty": 99,
                  "bloom_level": 0,
                  "concepts": ["Zlomky", ""]
                },
                {
                  "type": "display",
                  "steps": [
                    {"type": "question", "content": "Kolik je 1/2 + 1/4?",
                     "question": {"type": "multiple_choice",
                       "options": [
                         {"text": "3/4", "is_correct": true, "feedback": "Správně."},
                         {"text": "2/6", "is_correct": false}
                       ],
                       "solution": "Společný jmenovatel je 4."}}
                  ]
                },
                {
                  "type": "question",
                  "steps": [
                    {"type": "question", "content": "Bez možností",
                     "question": {"type": "multiple_choice", "options": [{"text": "jediná", "is_correct": true}]}}
                  ]
                },
                {"type": "display", "steps": []}
              ]
            },
            {"name": "Prázdná lekce", "blocks": []}
          ]
        }
        ```
        JSON);

        $draft = $service->normalizeDraft($decoded);

        // Lessons with no usable block are dropped entirely.
        $this->assertCount(1, $draft['lessons']);
        $lesson = $draft['lessons'][0];
        $this->assertSame('Zlomky', $lesson['name']);

        // Block 3 loses its question (only one option), block 4 has no steps —
        // both disappear, leaving the display block and the promoted question.
        $this->assertCount(2, $lesson['blocks']);

        [$display, $question] = $lesson['blocks'];

        // Blank text step removed, out-of-range metadata clamped, empty concept dropped.
        $this->assertCount(1, $display['steps']);
        $this->assertSame(5, $display['difficulty']);
        $this->assertSame(1, $display['bloom_level']);
        $this->assertSame(['Zlomky'], $display['concepts']);

        // A "display" block that actually carries a question is promoted.
        $this->assertSame('question', $question['type']);
        $this->assertSame('multiple_choice', $question['steps'][0]['question']['type']);
        $this->assertSame(['a', 'b'], array_column($question['steps'][0]['question']['options'], 'id'));
        $this->assertTrue($question['steps'][0]['question']['show_answers']);
    }

    public function test_unparsable_model_output_raises_a_clear_error(): void
    {
        $this->expectException(\RuntimeException::class);

        (new CourseGenerationService())->decodeDraft('Omlouvám se, ale nemohu pomoci.');
    }
}
