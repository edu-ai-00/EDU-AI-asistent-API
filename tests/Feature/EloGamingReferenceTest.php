<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EloInteractionController;
use App\Models\EloInteraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pilot 2 gaming rule, per task (BR-H3TCRE): once a task holds ten valid,
 * non-gaming reaction times from students, the app replaces the fixed 2 s
 * floor with a robust median/MAD threshold over them. The app already logs
 * those times — a quiz answer only becomes an elo_interaction when it was not
 * a guess, with the display → submit time the app measured — so this endpoint
 * only reads them back, keyed by block.
 */
class EloGamingReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/elo/gaming-reference';

    private function interaction(User $user, string $blockId, ?int $durationMs, string $source = 'quiz'): EloInteraction
    {
        return EloInteraction::create([
            'user_id' => $user->id,
            'block_id' => $blockId,
            'course_id' => 'course-g',
            'source' => $source,
            'score' => 1.0,
            'duration_ms' => $durationMs,
        ]);
    }

    public function test_returns_quiz_reaction_times_of_every_student_per_block(): void
    {
        $anna = User::factory()->create();
        $ben = User::factory()->create();
        $this->interaction($anna, 'blk_a', 4250);
        $this->interaction($ben, 'blk_a', 7125);
        $this->interaction($ben, 'blk_b', 12001);

        Sanctum::actingAs($anna);

        $data = $this->getJson(self::URL.'?block_ids=blk_a,blk_b')
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing([4.25, 7.125], $data['blk_a']);
        $this->assertSame([12.001], $data['blk_b']);
    }

    public function test_a_block_without_history_comes_back_empty(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::URL.'?block_ids=blk_new')
            ->assertOk()
            ->assertExactJson(['data' => ['blk_new' => []]]);
    }

    public function test_lesson_answers_are_not_part_of_the_reference(): void
    {
        $student = User::factory()->create();
        $this->interaction($student, 'blk_a', 3333, 'lesson');
        $this->interaction($student, 'blk_a', 5555);

        Sanctum::actingAs($student);

        $this->getJson(self::URL.'?block_ids=blk_a')
            ->assertOk()
            ->assertExactJson(['data' => ['blk_a' => [5.555]]]);
    }

    public function test_synthesised_and_missing_timings_are_left_out(): void
    {
        // runTimestampBackfill fills pre-tracking quiz rows with whole-second
        // estimates (created_at minus a gap); the app always sends the
        // millisecond duration it measured. An estimate is not a reaction time.
        $student = User::factory()->create();
        $this->interaction($student, 'blk_a', 25000);
        $this->interaction($student, 'blk_a', null);
        $this->interaction($student, 'blk_a', 0);
        $this->interaction($student, 'blk_a', 6789);

        Sanctum::actingAs($student);

        $this->getJson(self::URL.'?block_ids=blk_a')
            ->assertOk()
            ->assertExactJson(['data' => ['blk_a' => [6.789]]]);
    }

    public function test_teacher_and_admin_answers_do_not_count(): void
    {
        // The reference is the task's students (Pilot 2); staff clicking
        // through a quiz to check it would drag the threshold down.
        $student = User::factory()->create();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->interaction($student, 'blk_a', 8001);
        $this->interaction($teacher, 'blk_a', 2101);
        $this->interaction($admin, 'blk_a', 2201);

        Sanctum::actingAs($student);

        $this->getJson(self::URL.'?block_ids=blk_a')
            ->assertOk()
            ->assertExactJson(['data' => ['blk_a' => [8.001]]]);
    }

    public function test_only_the_most_recent_times_per_block_are_returned(): void
    {
        $student = User::factory()->create();
        $cap = EloInteractionController::GAMING_REFERENCE_LIMIT;
        // The oldest row is the one past the cap.
        $this->interaction($student, 'blk_a', 99999);
        for ($i = 0; $i < $cap; $i++) {
            $this->interaction($student, 'blk_a', 3001 + $i);
        }
        $this->interaction($student, 'blk_b', 4001);

        Sanctum::actingAs($student);

        $data = $this->getJson(self::URL.'?block_ids=blk_a,blk_b')
            ->assertOk()
            ->json('data');

        $this->assertCount($cap, $data['blk_a']);
        $this->assertNotContains(99.999, $data['blk_a']);
        $this->assertSame([4.001], $data['blk_b']);
    }

    public function test_requires_block_ids_and_a_signed_in_user(): void
    {
        $this->getJson(self::URL.'?block_ids=blk_a')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson(self::URL)->assertUnprocessable();
    }
}
