<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserProgress;
use App\Services\EloEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-3N79C3 — the ELO backfill applies the Pilot 2 gaming rule the app
 * applies live: a guessed answer moves neither the ratings nor the counters.
 *
 * The app skips a lesson block answered under 2 s from the moment it was shown
 * (gaming_filter.dart), and it persists that very pair as the block's
 * `block_timestamps` (opened_at / confirmed_at). The backfill reads the pair
 * back, so the two agree to the millisecond — the boundary fixtures here match
 * the ones in the app's gaming_filter_test.dart. Quiz answers carry the
 * client's verdict as `gamed` (BR-RQQVP6).
 */
class EloBackfillGamingTest extends TestCase
{
    use RefreshDatabase;

    private const COURSE = 'course-gaming';

    private const LESSON = 'L1';

    /** @return array{relation_vector: list<int>, elo_vector: list<float|null>} */
    private function gpf(): array
    {
        $relation = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
        $relation[0] = 2;
        $elo = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $elo[0] = 6.0;

        return ['relation_vector' => $relation, 'elo_vector' => $elo];
    }

    /** @param  list<string>  $blockIds */
    private function seedCourse(array $blockIds): Course
    {
        return Course::create([
            'course_id' => self::COURSE,
            'name' => 'Gaming course',
            'status' => 'published',
            'data' => [
                'course_id' => self::COURSE,
                'blocks' => array_map(
                    fn (string $id) => ['block_id' => $id, 'gpf' => $this->gpf()],
                    $blockIds
                ),
                'lessons' => [[
                    'id' => self::LESSON,
                    'order' => 1,
                    'blocks' => array_map(
                        fn (string $id, int $i) => ['block_id' => $id, 'order' => $i],
                        $blockIds,
                        array_keys($blockIds)
                    ),
                ]],
            ],
        ]);
    }

    /**
     * A student who finished [$blockIds] in the lesson, right and unaided,
     * with the given block_timestamps.
     *
     * @param  list<string>  $blockIds
     * @param  array<string, array{opened_at?: string, confirmed_at?: string}>  $timestamps
     */
    private function seedLessonAnswers(User $student, Course $course, array $blockIds, array $timestamps): void
    {
        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => self::COURSE,
            'lesson_id' => self::LESSON,
            'progress_data' => [
                'blocks' => array_fill_keys($blockIds, ['isCompleted' => true, 'isCorrect' => true]),
            ],
        ]);

        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [
                    self::LESSON => ['block_timestamps' => $timestamps],
                ],
            ],
        ]);
    }

    /** @return list<string> */
    private function interactionBlocks(User $student): array
    {
        return EloInteraction::where('user_id', $student->id)
            ->orderBy('block_id')
            ->pluck('block_id')
            ->all();
    }

    public function test_run_skips_lesson_answers_given_under_two_seconds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $blocks = ['blk_fast', 'blk_edge', 'blk_slow', 'blk_untimed'];
        $course = $this->seedCourse($blocks);

        $this->seedLessonAnswers($student, $course, $blocks, [
            // 1.999 s — a guess, same fixture as the app's filter test.
            'blk_fast' => ['opened_at' => '2026-09-01T12:00:00.000Z', 'confirmed_at' => '2026-09-01T12:00:01.999Z'],
            // Exactly 2 s is a real attempt.
            'blk_edge' => ['opened_at' => '2026-09-01T12:01:00.000Z', 'confirmed_at' => '2026-09-01T12:01:02.000Z'],
            'blk_slow' => ['opened_at' => '2026-09-01T12:02:00.000Z', 'confirmed_at' => '2026-09-01T12:02:30.000Z'],
            // No opened_at: nothing to measure, so no verdict — it counts.
            'blk_untimed' => ['confirmed_at' => '2026-09-01T12:03:00.000Z'],
        ]);

        Sanctum::actingAs($admin);

        $preview = $this->getJson('/api/admin/elo/backfill/preview/'.self::COURSE)->assertOk();
        // The confirm dialog promises this many interactions — the guess is
        // not one of them.
        $preview->assertJsonPath('data.pending_pairs', 3);

        $this->postJson('/api/admin/elo/backfill/run/'.self::COURSE)
            ->assertOk()
            ->assertJsonPath('data.interactions_created', 3);

        $this->assertSame(['blk_edge', 'blk_slow', 'blk_untimed'], $this->interactionBlocks($student));
    }

    public function test_run_skips_quiz_answers_the_client_flagged_as_guesses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $this->seedCourse(['blk_quiz_guess', 'blk_quiz_real']);

        QuizAttempt::create([
            'user_id' => $student->id,
            'course_id' => self::COURSE,
            'total_questions' => 2,
            'correct_answers' => 2,
            'score_percent' => 50,
            'answers' => [
                ['question_index' => 0, 'question_id' => 'blk_quiz_guess', 'is_correct' => true, 'gamed' => true],
                ['question_index' => 1, 'question_id' => 'blk_quiz_real', 'is_correct' => true],
            ],
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/elo/backfill/preview/'.self::COURSE)
            ->assertOk()
            ->assertJsonPath('data.pending_pairs', 1);

        $this->postJson('/api/admin/elo/backfill/run/'.self::COURSE)
            ->assertOk()
            ->assertJsonPath('data.interactions_created', 1);

        $this->assertSame(['blk_quiz_real'], $this->interactionBlocks($student));
    }

    public function test_a_student_who_only_guessed_keeps_an_untouched_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse(['blk_fast']);

        $this->seedLessonAnswers($student, $course, ['blk_fast'], [
            'blk_fast' => ['opened_at' => '2026-09-01T12:00:00.000Z', 'confirmed_at' => '2026-09-01T12:00:00.800Z'],
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/elo/backfill/run/'.self::COURSE)->assertOk();

        $profile = UserEloProfile::where('user_id', $student->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(array_fill(0, EloEngine::GPF_DIMENSIONS, null), $profile->profil_elo);
        $this->assertSame(array_fill(0, EloEngine::GPF_DIMENSIONS, 0), $profile->profil_pocet);
        $this->assertSame([], $this->interactionBlocks($student));
    }
}
