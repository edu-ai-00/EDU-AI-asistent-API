<?php

namespace Tests\Feature;

use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizAttemptIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_post_with_same_started_at_does_not_create_duplicates(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $payload = [
            'course_id' => 'EDU_ONBOARDING_APP',
            'total_questions' => 2,
            'correct_answers' => 1,
            'score_percent' => 50,
            'time_spent_seconds' => 56,
            'started_at' => '2026-03-04T18:21:30+00:00',
            'completed_at' => '2026-03-04T18:22:26+00:00',
            'answers' => [
                ['question_index' => 0, 'question_id' => 'q1', 'selected_answer' => 'opt_1', 'is_correct' => false],
                ['question_index' => 1, 'question_id' => 'q2', 'selected_answer' => 'opt_3', 'is_correct' => true],
            ],
        ];

        // Simulate the rapid-tap / retry burst we saw in production (7 calls).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/user/quiz-attempts', $payload)
                ->assertStatus($i === 0 ? 201 : 200);
        }

        $this->assertSame(1, QuizAttempt::where('user_id', $user->id)->count());
    }

    public function test_different_started_at_creates_separate_attempt(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $base = [
            'course_id' => 'C1',
            'total_questions' => 2,
            'correct_answers' => 1,
            'score_percent' => 50,
            'time_spent_seconds' => 30,
        ];

        $this->postJson('/api/user/quiz-attempts', [...$base, 'started_at' => '2026-03-04T18:00:00+00:00'])
            ->assertCreated();
        $this->postJson('/api/user/quiz-attempts', [...$base, 'started_at' => '2026-03-04T19:00:00+00:00'])
            ->assertCreated();

        $this->assertSame(2, QuizAttempt::where('user_id', $user->id)->count());
    }

    public function test_resubmission_updates_completion_data(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $startedAt = '2026-03-04T18:21:30+00:00';

        // First submit reports 56 seconds
        $this->postJson('/api/user/quiz-attempts', [
            'course_id' => 'C1',
            'total_questions' => 2,
            'correct_answers' => 1,
            'score_percent' => 50,
            'time_spent_seconds' => 56,
            'started_at' => $startedAt,
            'completed_at' => '2026-03-04T18:22:26+00:00',
        ])->assertCreated();

        // Second submit (rapid-tap) reports 58 seconds — should overwrite, not duplicate
        $this->postJson('/api/user/quiz-attempts', [
            'course_id' => 'C1',
            'total_questions' => 2,
            'correct_answers' => 2,
            'score_percent' => 100,
            'time_spent_seconds' => 58,
            'started_at' => $startedAt,
            'completed_at' => '2026-03-04T18:22:28+00:00',
        ])->assertOk();

        $row = QuizAttempt::where('user_id', $user->id)->sole();
        $this->assertSame(58, $row->time_spent_seconds);
        $this->assertSame(2, $row->correct_answers);
        $this->assertSame(100, $row->score_percent);
    }

    /**
     * A guess caught by the client's gaming filter is stored flagged, so the
     * attempt explains why its score is lower than its tally of right
     * answers (BR-RQQVP6).
     */
    public function test_guessed_answer_flag_is_stored(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/quiz-attempts', [
            'course_id' => 'C1',
            'total_questions' => 2,
            // The guess landed on the right option but scores as nothing.
            'correct_answers' => 1,
            'score_percent' => 50,
            'started_at' => '2026-09-04T08:00:00+00:00',
            'answers' => [
                ['question_index' => 0, 'question_id' => 'q1', 'is_correct' => true],
                ['question_index' => 1, 'question_id' => 'q2', 'is_correct' => true, 'gamed' => true],
            ],
        ])->assertCreated();

        $row = QuizAttempt::where('user_id', $user->id)->sole();
        $answers = is_string($row->answers) ? json_decode($row->answers, true) : $row->answers;

        $this->assertArrayNotHasKey('gamed', $answers[0]);
        $this->assertTrue($answers[1]['gamed']);
        $this->assertSame(1, $row->correct_answers);
    }

    public function test_legacy_payload_without_started_at_still_creates_row(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/quiz-attempts', [
            'course_id' => 'C1',
            'total_questions' => 2,
            'correct_answers' => 1,
            'score_percent' => 50,
        ])->assertCreated();

        $this->assertSame(1, QuizAttempt::where('user_id', $user->id)->count());
    }
}
