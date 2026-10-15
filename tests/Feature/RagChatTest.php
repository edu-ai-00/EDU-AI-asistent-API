<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\RagSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chat sessions with the skolnik/cermatik personas are answered by the RAG
 * search service (app chat -> backend -> RAG) instead of the OpenRouter LLM.
 */
class RagChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.base_url' => 'http://rag.test/search/v1',
            'rag.api_key' => 'test-rag-key',
            'rag.timeout' => 5,
            'rag.legal_entity' => 'ZS_TEST',
        ]);
    }

    private function fakeRagAnswer(array $overrides = []): void
    {
        Http::fake([
            'rag.test/search/v1/ask' => Http::response(array_merge([
                'answer' => 'Přijímačky jsou **10. a 13. dubna 2026**.',
                'outcome' => 'answer',
                'answer_source' => 'rag',
                'suggestions' => [
                    ['entry_id' => '', 'question' => 'Jak se hodnotí záznamový arch?'],
                ],
                'matched_entry_id' => null,
                'match_score' => 0.82,
                'sources' => [
                    ['rank' => 1, 'title' => 'Průvodce přijímačkami', 'url' => 'https://prijimacky.cermat.cz'],
                ],
                'answer_parts' => [],
                'trace' => [],
            ], $overrides)),
        ]);
    }

    public function test_creating_a_rag_persona_session_assigns_a_conversation_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/chat/sessions', ['persona' => 'cermatik']);

        $response->assertStatus(201);
        $session = ChatSession::findOrFail($response->json('data.id'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $session->rag_conversation_id,
        );
    }

    public function test_non_rag_persona_session_has_no_conversation_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/chat/sessions', ['persona' => 'ai_teacher']);

        $response->assertStatus(201);
        $session = ChatSession::findOrFail($response->json('data.id'));
        $this->assertNull($session->rag_conversation_id);
    }

    public function test_unknown_persona_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat/sessions', ['persona' => 'hacker'])->assertStatus(422);
    }

    public function test_stream_answers_from_rag_and_persists_metadata(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
            'rag_conversation_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ])->assertStatus(201);
        $assistantId = $store->json('data.assistant_message.id');

        $content = $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('10. a 13. dubna 2026', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://rag.test/search/v1/ask'
                && $request->hasHeader('Authorization', 'test-rag-key')
                && $request->hasHeader('Idempotency-Key')
                && $request['question'] === 'Kdy jsou přijímačky?'
                && $request['legal_entity'] === 'ZS_TEST'
                && $request['user_role'] === 'STUDENT'
                && $request['conversation_id'] === '11111111-1111-1111-1111-111111111111'
                && $request['turn_seq'] === 1
                && !array_key_exists('debug', $request->data())
                // Školník has no domains: the closed /ask schema must not get
                // an empty list, it gets no key at all.
                && !array_key_exists('domains', $request->data());
        });

        $assistant = $session->messages()->findOrFail($assistantId);
        $this->assertStringContainsString('Přijímačky jsou **10. a 13. dubna 2026**.', $assistant->content);
        $this->assertStringContainsString('**Mohlo by tě zajímat:**', $assistant->content);
        $this->assertStringContainsString('- Jak se hodnotí záznamový arch?', $assistant->content);
        $this->assertStringContainsString('[Průvodce přijímačkami](https://prijimacky.cermat.cz)', $assistant->content);
        $this->assertSame('rag', $assistant->metadata['source']);
        $this->assertSame('answer', $assistant->metadata['outcome']);
        $this->assertCount(1, $assistant->metadata['sources']);
    }

    /**
     * BR-DAB49K — Cermatík and Školník search the same legal entity; what
     * keeps Cermatík on the admissions documents is the `domains` filter the
     * RAG provider asked for. It lives on the mentor row (seeded for cermatik
     * by the migration) so the admin can change it without a deploy.
     */
    public function test_cermatik_sends_its_domains_to_the_rag_service(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'cermatik',
            'last_message_at' => now(),
            'rag_conversation_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ])->assertStatus(201);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://rag.test/search/v1/ask'
                && $request['legal_entity'] === 'ZS_TEST'
                && $request['user_role'] === 'STUDENT'
                && $request['domains'] === ['SECONDARY_ADMISSIONS'];
        });
    }

    /**
     * BR-K2DAVZ — the RAG service builds source titles out of the document URL
     * and cuts them to a fixed length; a cut landing inside a percent escape
     * arrives decoded as "… K PŘIJ\u{FFFD}%". The URL survives whole, so the
     * label is rebuilt from it instead of showing the damage to the student.
     */
    public function test_a_damaged_source_title_is_rebuilt_from_the_url(): void
    {
        $rag = new RagSearchService('http://rag.test/search/v1', 'test-rag-key', 5, 'ZS_TEST');

        $text = $rag->formatAnswer([
            'answer' => 'Ano.',
            'sources' => [
                [
                    'title' => "prijimacky.cermat.cz files files PRŮVODCE PRO UCHAZEČE K PŘIJ\u{FFFD}%",
                    'url' => 'https://prijimacky.cermat.cz/files/files/PR%C5%AEVODCE%20PRO%20UCHAZE%C4%8CE.pdf',
                ],
                ['title' => 'Průvodce přijímačkami', 'url' => 'https://prijimacky.cermat.cz'],
            ],
        ]);

        $this->assertStringNotContainsString("\u{FFFD}", $text);
        $this->assertStringContainsString(
            '- [PRŮVODCE PRO UCHAZEČE.pdf](https://prijimacky.cermat.cz/files/files/PR%C5%AEVODCE%20PRO%20UCHAZE%C4%8CE.pdf)',
            $text,
        );
        // An intact title is still shown as the service sent it.
        $this->assertStringContainsString('- [Průvodce přijímačkami](https://prijimacky.cermat.cz)', $text);
    }

    /**
     * BR-K2DAVZ — with no URL to fall back on, the damaged tail is dropped and
     * the readable part of the title kept.
     */
    public function test_a_damaged_source_title_without_a_url_keeps_its_readable_part(): void
    {
        $rag = new RagSearchService('http://rag.test/search/v1', 'test-rag-key', 5, 'ZS_TEST');

        $text = $rag->formatAnswer([
            'answer' => 'Ano.',
            'sources' => [['title' => "Průvodce pro uchazeče o PŘIJ\u{FFFD}%", 'url' => '']],
        ]);

        $this->assertStringContainsString('- Průvodce pro uchazeče o PŘIJ', $text);
        $this->assertStringNotContainsString("\u{FFFD}", $text);
    }

    /**
     * BR-UE8KK4 — the app draws the follow-up questions as buttons, so they
     * have to arrive as data and not only as bullets inside the answer text.
     */
    public function test_stream_emits_the_follow_up_questions_as_their_own_frame(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ])->assertStatus(201);
        $assistantId = $store->json('data.assistant_message.id');

        $content = $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(
            'data: ' . json_encode(['suggestions' => [
                ['entry_id' => '', 'question' => 'Jak se hodnotí záznamový arch?'],
            ]]),
            $content,
        );

        // The markdown copy stays put — app builds that only read `token`
        // would otherwise lose the section entirely. The frames are JSON, so
        // the heading is escaped in there.
        $this->assertStringContainsString(
            trim(json_encode('**Mohlo by tě zajímat:**'), '"'),
            $content,
        );
    }

    public function test_stream_omits_the_suggestions_frame_when_there_are_none(): void
    {
        $this->fakeRagAnswer(['suggestions' => []]);
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ])->assertStatus(201);
        $assistantId = $store->json('data.assistant_message.id');

        $content = $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('suggestions', $content);
    }

    /**
     * BR-UE8KK4 — reopening a chat has to bring the buttons back, so the
     * history endpoint the app syncs from carries the metadata too.
     */
    public function test_message_history_returns_the_answer_metadata(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ])->assertStatus(201);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $response = $this->getJson('/api/chat/messages')->assertOk();

        $assistant = collect($response->json('data'))
            ->firstWhere('id', $assistantId);

        $this->assertSame(
            [['entry_id' => '', 'question' => 'Jak se hodnotí záznamový arch?']],
            $assistant['metadata']['suggestions'],
        );
    }

    public function test_stream_maps_no_answer_outcome_to_fallback_text(): void
    {
        $this->fakeRagAnswer([
            'answer' => '',
            'outcome' => 'no_answer',
            'answer_source' => 'none',
            'suggestions' => [],
            'sources' => [],
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'cermatik',
            'last_message_at' => now(),
            'rag_conversation_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Něco úplně mimo?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $assistant = $session->messages()->findOrFail($assistantId);
        $this->assertStringContainsString('odpověď nenašel', $assistant->content);
        $this->assertSame('no_answer', $assistant->metadata['outcome']);
    }

    public function test_stream_emits_error_and_keeps_message_empty_on_rag_failure(): void
    {
        Http::fake([
            'rag.test/search/v1/ask' => Http::response(['error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'boom',
                'retryable' => true,
            ]], 500),
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
            'rag_conversation_id' => '33333333-3333-3333-3333-333333333333',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $content = $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('AI generation failed', $content);
        $this->assertStringContainsString('"reason":"upstream_error"', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        // Content stays empty so a reconnect retries instead of replaying,
        // but the classified failure is recorded on the message.
        $assistant = $session->messages()->findOrFail($assistantId);
        $this->assertSame('', $assistant->content);
        $this->assertSame('upstream_error', $assistant->metadata['error']);
        $this->assertStringContainsString('INTERNAL_ERROR', $assistant->metadata['error_detail']);
    }

    public function test_cloudflare_challenge_is_classified(): void
    {
        Http::fake([
            'rag.test/search/v1/ask' => Http::response(
                '<html><script>window._cf_chl_opt = {};</script>Just a moment...</html>',
                403,
            ),
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'cermatik',
            'last_message_at' => now(),
            'rag_conversation_id' => '55555555-5555-5555-5555-555555555555',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->streamedContent();

        $this->assertSame(
            'cloudflare_challenge',
            $session->messages()->findOrFail($assistantId)->metadata['error'],
        );
    }

    public function test_redirect_is_classified_instead_of_followed(): void
    {
        Http::fake([
            'rag.test/search/v1/ask' => Http::response('', 301, ['Location' => 'https://rag.test/search/v1/ask']),
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
            'rag_conversation_id' => '66666666-6666-6666-6666-666666666666',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->streamedContent();

        $assistant = $session->messages()->findOrFail($assistantId);
        $this->assertSame('redirected', $assistant->metadata['error']);
        $this->assertStringContainsString('RAG_BASE_URL', $assistant->metadata['error_detail']);
    }

    public function test_admin_rag_health_reports_each_step(): void
    {
        Http::fake([
            'rag.test/search/health' => Http::response(['status' => 'ok']),
            'rag.test/search/v1/entities' => Http::response(['data' => [['code' => 'ZS_TEST']]]),
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.7']),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin/rag/health')->assertOk();

        $this->assertTrue($response->json('data.ok'));
        $this->assertTrue($response->json('data.api_key_set'));
        $this->assertSame('203.0.113.7', $response->json('data.egress_ip'));
        $this->assertSame(['config', 'health', 'entities'], array_column($response->json('data.checks'), 'name'));
    }

    public function test_admin_rag_health_flags_cloudflare_block(): void
    {
        Http::fake([
            'rag.test/search/health' => Http::response('Just a moment... _cf_chl_opt', 403),
            'rag.test/search/v1/entities' => Http::response('Just a moment... _cf_chl_opt', 403),
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.7']),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin/rag/health')->assertOk();

        $this->assertFalse($response->json('data.ok'));
        $checks = collect($response->json('data.checks'))->keyBy('name');
        $this->assertFalse($checks['health']['ok']);
        $this->assertSame('cloudflare_challenge', $checks['entities']['reason']);
    }

    public function test_stray_punctuation_in_base_url_is_stripped(): void
    {
        config(['rag.base_url' => " http://rag.test/search/v1,\n"]);
        $this->fakeRagAnswer();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
            'rag_conversation_id' => '88888888-8888-8888-8888-888888888888',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->streamedContent();

        Http::assertSent(fn ($request) => $request->url() === 'http://rag.test/search/v1/ask');
        $this->assertStringContainsString(
            '10. a 13. dubna 2026',
            $session->messages()->findOrFail($assistantId)->content,
        );
    }

    public function test_cf_access_service_token_headers_are_sent_when_configured(): void
    {
        config([
            'rag.cf_access_client_id' => 'cf-id.access',
            'rag.cf_access_client_secret' => 'cf-secret',
        ]);
        $this->fakeRagAnswer();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'cermatik',
            'last_message_at' => now(),
            'rag_conversation_id' => '77777777-7777-7777-7777-777777777777',
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->streamedContent();

        Http::assertSent(function ($request) {
            return $request->hasHeader('CF-Access-Client-Id', 'cf-id.access')
                && $request->hasHeader('CF-Access-Client-Secret', 'cf-secret')
                && $request->hasHeader('Authorization', 'test-rag-key');
        });
    }

    public function test_cf_access_token_alone_counts_as_configured(): void
    {
        config([
            'rag.api_key' => null,
            'rag.cf_access_client_id' => 'cf-id.access',
            'rag.cf_access_client_secret' => 'cf-secret',
        ]);
        Http::fake([
            'rag.test/search/health' => Http::response(['status' => 'ok']),
            'rag.test/search/v1/entities' => Http::response(['data' => [['code' => 'ZS_TEST']]]),
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.7']),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin/rag/health')->assertOk();

        $this->assertTrue($response->json('data.ok'));
        $this->assertTrue($response->json('data.cf_access_token_set'));
        $this->assertStringContainsString('CF Access', $response->json('data.checks.0.detail'));
    }

    public function test_admin_rag_health_base_url_override_is_restricted_to_rag_host(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/admin/rag/health?base_url=' . urlencode('https://evil.example.com/v1'))
            ->assertStatus(422);
        $this->getJson('/api/admin/rag/health?base_url=' . urlencode('https://rag.evil.example.com/v1'))
            ->assertStatus(422);

        Http::fake([
            'rag.edu-ai.eu/search/health' => Http::response(['status' => 'ok']),
            'rag.edu-ai.eu/search/v1/entities' => Http::response(['data' => [['code' => 'ZS_TEST']]]),
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.7']),
        ]);
        $response = $this->getJson('/api/admin/rag/health?base_url=' . urlencode('http://rag.edu-ai.eu/search/v1'))
            ->assertOk();
        $this->assertSame('http://rag.edu-ai.eu/search/v1', $response->json('data.base_url'));
        $this->assertTrue($response->json('data.ok'));
    }

    public function test_admin_rag_health_reports_missing_config(): void
    {
        config(['rag.api_key' => null]);
        Http::fake(['api.ipify.org*' => Http::response(['ip' => '203.0.113.7'])]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin/rag/health')->assertOk();

        $this->assertFalse($response->json('data.ok'));
        $this->assertFalse($response->json('data.api_key_set'));
        $this->assertStringContainsString('RAG_API_KEY', $response->json('data.checks.0.detail'));
    }

    public function test_stream_generates_conversation_uuid_for_legacy_rag_sessions(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'cermatik',
            'last_message_at' => now(),
        ]);

        $store = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Kdy jsou přijímačky?',
        ]);
        $assistantId = $store->json('data.assistant_message.id');

        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$assistantId}")
            ->assertOk()
            ->streamedContent();

        $this->assertNotNull($session->fresh()->rag_conversation_id);
    }

    public function test_turn_seq_counts_user_messages_in_the_session(): void
    {
        $this->fakeRagAnswer();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'persona' => 'skolnik',
            'last_message_at' => now(),
            'rag_conversation_id' => '44444444-4444-4444-4444-444444444444',
        ]);

        // First turn: answered by the fake.
        $first = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'První otázka?',
        ]);
        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$first->json('data.assistant_message.id')}")
            ->streamedContent();

        // Second turn.
        $second = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Druhá otázka?',
        ]);
        $this->get("/api/chat/sessions/{$session->id}/stream?message_id={$second->json('data.assistant_message.id')}")
            ->streamedContent();

        Http::assertSent(fn ($request) => $request['turn_seq'] === 2 && $request['question'] === 'Druhá otázka?');
    }
}
