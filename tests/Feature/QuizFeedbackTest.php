<?php

namespace Tests\Feature;

use App\Models\Mentor;
use App\Models\User;
use App\Services\QuizFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI comment for the end-of-quiz feedback popup ("Quiz Feedback v1").
 */
class QuizFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'chat.api_key' => 'test-key',
            'chat.api_url' => 'https://openrouter.test/api/v1',
            'chat.model' => 'test/model',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'tasks' => [
                [
                    'assignment' => 'Kolik je 2 + 3?',
                    'outcome' => 'correct_no_hint',
                    'hint_used' => false,
                    'help_used' => false,
                    'duration_ms' => 9000,
                ],
                [
                    'assignment' => 'Vypočítej obsah čtverce o straně 4 cm.',
                    'outcome' => 'gamed',
                    'hint_used' => false,
                    'help_used' => false,
                    'duration_ms' => 800,
                ],
            ],
            'domain_deltas' => ['N' => 0.12, 'M' => -0.05],
        ];
    }

    private function fakeModelComment(string $text): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $text]],
                ],
            ]),
        ]);
    }

    public function test_returns_the_model_comment(): void
    {
        $this->fakeModelComment('Skvělá práce! Zkus si příště dát pozor na příliš rychlé odpovědi.');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/quiz/feedback', $this->payload());

        $response->assertOk()->assertJsonPath(
            'data.comment',
            'Skvělá práce! Zkus si příště dát pozor na příliš rychlé odpovědi.',
        );

        // The block's results actually reach the model.
        Http::assertSent(function ($request) {
            $user = collect($request['messages'])->firstWhere('role', 'user')['content'] ?? '';

            return str_contains($user, 'Kolik je 2 + 3?')
                && str_contains($user, 'gamed')
                && str_contains($user, '<zmeny_elo>');
        });
    }

    public function test_uses_the_admin_edited_prompt_from_the_mentors_row(): void
    {
        $this->fakeModelComment('Komentář.');
        Mentor::query()->where('slug', QuizFeedbackService::PROMPT_SLUG)->update([
            'ai_context' => 'UPRAVENÝ PROMPT Z ADMINU',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/quiz/feedback', $this->payload())->assertOk();

        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($system, 'UPRAVENÝ PROMPT Z ADMINU');
        });
    }

    public function test_model_failure_returns_503(): void
    {
        Http::fake(['openrouter.test/*' => Http::response('upstream down', 500)]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/quiz/feedback', $this->payload())
            ->assertStatus(503);
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/quiz/feedback', [
            'tasks' => [['outcome' => 'perfect']],
        ])->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/quiz/feedback', $this->payload())
            ->assertStatus(401);
    }
}
