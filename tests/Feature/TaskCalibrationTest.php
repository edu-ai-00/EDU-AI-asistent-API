<?php

namespace Tests\Feature;

use App\Models\BlockStat;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserProgress;
use App\Services\EloEngine;
use App\Services\TaskCalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Server-side task difficulty calibration (BR-697NCA, design BR-QF2N45).
 *
 * The app keeps computing the student's rating L; the server is the only
 * writer of a block's difficulty D and its update count m, applying the task
 * half of every eligible answer as it arrives and replaying all of them in
 * answer order on recalibration. The numbers in the sequential test are the
 * §2 table of the design: three students with L = 5 solve a fresh task with
 * D = 5, m = 0.
 */
class TaskCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private const COURSE = 'course-cal';

    private const BLOCK = 'blk_cal';

    /** The one active dimension of the test block. */
    private const K = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['elo.task_calibration' => true]);
        $this->seedCourse();
    }

    /**
     * @param  array<string, float|null>  $priors  block_id → D prior in dimension K (null = no GPF)
     */
    private function seedCourse(array $priors = [self::BLOCK => 5.0, 'blk_other' => 6.0], string $courseId = self::COURSE): Course
    {
        $blocks = [];
        foreach ($priors as $blockId => $prior) {
            $block = ['block_id' => $blockId, 'type' => 'quiz'];
            if ($prior !== null) {
                $relation = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
                $relation[self::K] = 2;
                $elo = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
                $elo[self::K] = $prior;
                $block['gpf'] = ['relation_vector' => $relation, 'elo_vector' => $elo];
            }
            $blocks[] = $block;
        }

        return Course::create([
            'course_id' => $courseId,
            'name' => 'Calibration course',
            'status' => 'published',
            'data' => [
                'course_id' => $courseId,
                'blocks' => $blocks,
                'lessons' => [[
                    'id' => 'L1',
                    'order' => 1,
                    'blocks' => array_map(
                        fn (string $id, int $i) => ['block_id' => $id, 'order' => $i],
                        array_keys($priors),
                        array_keys(array_keys($priors))
                    ),
                ]],
            ],
        ]);
    }

    /** L with a rating only in dimension K (null = unrated). */
    private function rating(?float $l): array
    {
        $vector = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $vector[self::K] = $l;

        return $vector;
    }

    /** What an app build with this change sends. */
    private function payload(?float $lBefore = 5.0, array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'block_id' => self::BLOCK,
            'course_id' => self::COURSE,
            'source' => 'quiz',
            'score' => 1.0,
            'profil_elo_before' => $this->rating($lBefore),
            'profil_elo_snapshot' => $this->rating($lBefore),
            'updated_indices' => [self::K],
            'elo_config_version' => EloEngine::CONFIG_VERSION,
        ], $overrides);
    }

    private function answerAs(User $user, array $payload): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/elo/interactions', $payload);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function stat(string $blockId = self::BLOCK): ?BlockStat
    {
        return BlockStat::where('block_id', $blockId)->first();
    }

    public function test_sequential_answers_move_d_with_a_decaying_k(): void
    {
        foreach ([4.750, 4.543, 4.369] as $i => $expected) {
            $this->answerAs($this->student(), $this->payload())
                ->assertCreated()
                ->assertJsonPath('task_applied', true);

            $stat = $this->stat();
            $this->assertEqualsWithDelta($expected, $stat->elo_vector[self::K], 5e-4);
            $this->assertSame($i + 1, $stat->item_pocet[self::K]);
            // Uncalibrated dimensions stay unset: the content prior applies there.
            $this->assertNull($stat->elo_vector[self::K + 1]);
        }

        // Each row records the server's D after it.
        $snapshots = EloInteraction::orderBy('id')->get()->map(fn ($i) => round($i->elo_vector_snapshot[self::K], 3));
        $this->assertSame([4.75, 4.543, 4.369], $snapshots->all());
    }

    public function test_a_retried_send_with_the_same_client_uuid_changes_nothing(): void
    {
        $student = $this->student();
        $payload = $this->payload();

        $first = $this->answerAs($student, $payload)->assertCreated();
        $this->answerAs($student, $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));

        $this->assertSame(1, EloInteraction::count());
        $this->assertSame(1, $this->stat()->item_pocet[self::K]);
    }

    public function test_falling_back_to_single_sends_after_a_batch_does_not_double_apply(): void
    {
        // The app's recovery path: the batch committed server-side but the
        // response never arrived, so it re-sends every item one by one.
        $student = $this->student();
        $a = $this->payload();
        $b = $this->payload(overrides: ['block_id' => 'blk_other']);

        Sanctum::actingAs($student);
        $this->postJson('/api/elo/interactions/batch', ['interactions' => [$a, $b]])
            ->assertOk()
            ->assertJsonPath('data.0.task_applied', true)
            ->assertJsonPath('data.1.task_applied', true);

        $this->postJson('/api/elo/interactions', $a)->assertOk();
        $this->postJson('/api/elo/interactions', $b)->assertOk();

        $this->assertSame(2, EloInteraction::count());
        $this->assertSame(1, $this->stat()->item_pocet[self::K]);
        $this->assertSame(1, $this->stat('blk_other')->item_pocet[self::K]);
    }

    public function test_staff_answers_are_logged_without_moving_d(): void
    {
        foreach (['teacher', 'admin'] as $role) {
            $this->answerAs(User::factory()->create(['role' => $role]), $this->payload())
                ->assertCreated()
                ->assertJsonPath('task_applied', false);
        }

        $this->assertSame(['role', 'role'], EloInteraction::pluck('task_skip_reason')->all());
        $this->assertSame(0, $this->stat()?->item_pocet[self::K] ?? 0);
    }

    public function test_guests_calibrate_like_students(): void
    {
        $guest = User::factory()->create(['role' => 'student', 'is_guest' => true]);

        $this->answerAs($guest, $this->payload())->assertJsonPath('task_applied', true);
    }

    public function test_only_the_first_attempt_at_a_block_moves_d(): void
    {
        $student = $this->student();

        $this->answerAs($student, $this->payload())->assertJsonPath('task_applied', true);
        $this->answerAs($student, $this->payload(5.5, ['score' => 0.0]))->assertJsonPath('task_applied', false);

        $this->assertSame('repeat_attempt', EloInteraction::orderByDesc('id')->first()->task_skip_reason);
        $this->assertSame(1, $this->stat()->item_pocet[self::K]);
        $this->assertEqualsWithDelta(4.75, $this->stat()->elo_vector[self::K], 1e-9);
    }

    public function test_an_older_build_without_l_before_is_logged_not_applied(): void
    {
        $payload = $this->payload();
        unset($payload['profil_elo_before'], $payload['client_uuid']);
        $payload['elo_vector_snapshot'] = $this->rating(5.0);
        $payload['item_pocet_delta'] = array_fill(0, EloEngine::GPF_DIMENSIONS, 1);

        $this->answerAs($this->student(), $payload)->assertCreated()->assertJsonPath('task_applied', false);

        $row = EloInteraction::sole();
        $this->assertSame('legacy_payload', $row->task_skip_reason);
        // The client's item_pocet_delta is ignored; the server is the only writer of m.
        $this->assertSame(0, $this->stat()?->item_pocet[self::K] ?? 0);
    }

    public function test_first_contact_only_seeds_l(): void
    {
        $this->answerAs($this->student(), $this->payload(null))->assertJsonPath('task_applied', false);

        $this->assertSame('first_contact', EloInteraction::sole()->task_skip_reason);
        $this->assertSame(0, $this->stat()->item_pocet[self::K]);
    }

    public function test_a_block_without_gpf_moves_nothing(): void
    {
        Course::query()->delete();
        $this->seedCourse(['blk_text' => null], 'course-text');

        $this->answerAs($this->student(), $this->payload(overrides: ['block_id' => 'blk_text', 'course_id' => 'course-text']))
            ->assertJsonPath('task_applied', false);

        $this->assertSame('no_active_dimension', EloInteraction::sole()->task_skip_reason);
    }

    public function test_switched_off_everything_is_logged_and_nothing_moves(): void
    {
        config(['elo.task_calibration' => false]);

        $this->answerAs($this->student(), $this->payload())->assertJsonPath('task_applied', false);

        $row = EloInteraction::sole();
        $this->assertSame('disabled', $row->task_skip_reason);
        $this->assertEqualsWithDelta(5.0, $row->elo_vector_snapshot[self::K], 1e-9);
        $this->assertNull($this->stat());
    }

    public function test_the_global_quiz_calibrates_the_block_in_its_real_course(): void
    {
        $this->answerAs($this->student(), $this->payload(overrides: ['course_id' => 'GLOBAL_QUIZ']))
            ->assertJsonPath('task_applied', true);

        $this->assertEqualsWithDelta(4.75, $this->stat()->elo_vector[self::K], 1e-9);
    }

    public function test_a_calibrated_dimension_keeps_its_value_when_the_author_edits_the_prior(): void
    {
        // D2 as decided: no reset — the editor's change applies only where
        // nothing is calibrated yet.
        $this->answerAs($this->student(), $this->payload())->assertJsonPath('task_applied', true);

        $course = Course::where('course_id', self::COURSE)->first();
        $data = $course->data;
        $data['blocks'][0]['gpf']['elo_vector'][self::K] = 8.0;
        $course->update(['data' => $data]);

        $this->answerAs($this->student(), $this->payload())->assertJsonPath('task_applied', true);

        $this->assertSame(2, $this->stat()->item_pocet[self::K]);
        $this->assertEqualsWithDelta(4.543, $this->stat()->elo_vector[self::K], 5e-4);
    }

    public function test_recalibration_reproduces_live_application_in_the_same_order(): void
    {
        $answers = [[5.0, 1.0], [4.0, 0.0], [6.5, 1.0], [3.0, 0.5], [7.0, 0.0]];
        $students = [];
        foreach ($answers as $i => [$l, $score]) {
            $students[] = $student = $this->student();
            $this->answerAs($student, $this->payload($l, [
                'score' => $score,
                'confirmed_at' => now()->addMinutes($i)->toIso8601String(),
            ]));
        }
        // Noise the replay must classify exactly as live did: a teacher, and
        // the first student's retry.
        $this->answerAs(User::factory()->create(['role' => 'teacher']), $this->payload(overrides: [
            'confirmed_at' => now()->addMinutes(10)->toIso8601String(),
        ]));
        $this->answerAs($students[0], $this->payload(6.0, [
            'confirmed_at' => now()->addMinutes(11)->toIso8601String(),
        ]));

        $live = $this->calibrationSnapshot();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/elo/recalibrate/'.self::COURSE)
            ->assertOk()
            ->assertJsonPath('data.done', true)
            ->assertJsonPath('data.applied', 5)
            ->assertJsonPath('data.changed', 0);

        $this->assertEquals($live, $this->calibrationSnapshot());
    }

    public function test_recalibration_replays_in_answer_order_not_arrival_order(): void
    {
        // An offline batch arrives after an online answer it preceded.
        $late = $this->student();
        $early = $this->student();
        $this->answerAs($early, $this->payload(5.0, ['confirmed_at' => '2026-09-29T10:00:00Z']));
        $this->answerAs($late, $this->payload(5.0, ['score' => 0.0, 'confirmed_at' => '2026-09-29T09:00:00Z']));

        $this->artisan('elo:recalibrate', ['course_id' => self::COURSE])->assertSuccessful();

        // Replayed: the fail at D = 5 (m = 0) first, then the success.
        $expected = EloEngine::updateTask($this->rating(5.0), array_fill(0, 35, 0), $this->relation(), $this->rating(5.0), array_fill(0, 35, 0), 0.0);
        $expected = EloEngine::updateTask($this->rating(5.0), array_fill(0, 35, 0), $this->relation(), $expected['elo_vector'], $expected['item_pocet'], 1.0);

        $this->assertEqualsWithDelta($expected['elo_vector'][self::K], $this->stat()->elo_vector[self::K], 1e-12);
        $this->assertSame(2, $this->stat()->item_pocet[self::K]);
    }

    public function test_recalibration_folds_in_older_builds_by_reconstructing_l(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);

        // L after the student's previous answer (elsewhere) is L before this one.
        $this->postJson('/api/elo/interactions', [
            'block_id' => 'blk_other',
            'course_id' => self::COURSE,
            'score' => 1.0,
            'profil_elo_snapshot' => $this->rating(5.0),
            'confirmed_at' => '2026-09-29T08:00:00Z',
        ]);
        $this->postJson('/api/elo/interactions', [
            'block_id' => self::BLOCK,
            'course_id' => self::COURSE,
            'score' => 1.0,
            'profil_elo_snapshot' => $this->rating(5.5),
            'confirmed_at' => '2026-09-29T08:05:00Z',
        ]);
        $this->assertSame(['legacy_payload', 'legacy_payload'], EloInteraction::orderBy('id')->pluck('task_skip_reason')->all());

        $this->artisan('elo:recalibrate')->assertSuccessful();

        $row = EloInteraction::where('block_id', self::BLOCK)->sole();
        $this->assertTrue($row->task_applied);
        // L = 5 (reconstructed), D = 5, m = 0, success → 4.75.
        $this->assertEqualsWithDelta(4.75, $row->elo_vector_snapshot[self::K], 1e-9);
        // Its first answer ever had no earlier L: first contact.
        $this->assertSame('first_contact', EloInteraction::where('block_id', 'blk_other')->sole()->task_skip_reason);
    }

    public function test_recalibration_pages_through_the_course_and_a_dry_run_writes_nothing(): void
    {
        $this->answerAs($this->student(), $this->payload());
        $this->answerAs($this->student(), $this->payload(overrides: ['block_id' => 'blk_other']));
        BlockStat::query()->update(['elo_vector' => null, 'item_pocet' => null]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/elo/recalibrate/'.self::COURSE.'?dry_run=1')
            ->assertOk()
            ->assertJsonPath('data.applied', 2)
            ->assertJsonPath('data.dry_run', true);
        $this->assertNull($this->stat()->item_pocet);

        $this->postJson('/api/admin/elo/recalibrate/'.self::COURSE.'?limit=1')
            ->assertJsonPath('data.blocks_total', 2)
            ->assertJsonPath('data.next_offset', 1)
            ->assertJsonPath('data.done', false);
        $this->postJson('/api/admin/elo/recalibrate/'.self::COURSE.'?limit=1&offset=1')
            ->assertJsonPath('data.done', true);

        $this->assertSame(1, $this->stat()->item_pocet[self::K]);
        $this->assertSame(1, $this->stat('blk_other')->item_pocet[self::K]);
    }

    public function test_recalibrate_is_admin_only(): void
    {
        Sanctum::actingAs($this->student());
        $this->postJson('/api/admin/elo/recalibrate/'.self::COURSE)->assertForbidden();
    }

    public function test_stats_publish_calibrated_d_per_course(): void
    {
        $this->answerAs($this->student(), $this->payload());

        $response = $this->getJson('/api/blocks/stats?course_id='.self::COURSE)
            ->assertOk()
            ->assertJsonPath('meta.task_calibration', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.block_id', self::BLOCK)
            ->assertJsonPath('data.0.item_pocet.0', 1)
            ->assertJsonPath('data.0.elo_vector.1', null);
        $this->assertEqualsWithDelta(4.75, $response->json('data.0.elo_vector.0'), 1e-9);

        // Another course's blocks are not part of this course's pull.
        $this->getJson('/api/blocks/stats?course_id=course-unknown')->assertJsonCount(0, 'data');

        // The cursor is inclusive and filters older rows out.
        $updatedAt = $response->json('data.0.updated_at');
        $this->getJson('/api/blocks/stats?course_id='.self::COURSE.'&updated_since='.urlencode($updatedAt))
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/blocks/stats?course_id='.self::COURSE.'&updated_since='.urlencode(now()->addHour()->toIso8601String()))
            ->assertJsonCount(0, 'data');
    }

    public function test_stats_publish_nothing_while_switched_off(): void
    {
        $this->answerAs($this->student(), $this->payload());
        config(['elo.task_calibration' => false]);

        $this->getJson('/api/blocks/stats?course_id='.self::COURSE)
            ->assertOk()
            ->assertJsonPath('meta.task_calibration', false)
            ->assertJsonCount(0, 'data');

        // The admin still sees the calibrated values while checking them.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/blocks/stats?course_id='.self::COURSE)
            ->assertOk()
            ->assertJsonPath('meta.task_calibration', false)
            ->assertJsonCount(1, 'data');
    }

    public function test_backfill_logs_l_before_and_recalibrates_instead_of_writing_block_stats(): void
    {
        $student = $this->student();
        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => self::COURSE,
            'lesson_id' => 'L1',
            'progress_data' => [
                'blocks' => [
                    'blk_other' => ['isCompleted' => true, 'isCorrect' => true],
                    self::BLOCK => ['isCompleted' => true, 'isCorrect' => true],
                ],
            ],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/elo/backfill/run/'.self::COURSE)
            ->assertOk()
            ->assertJsonPath('data.interactions_created', 2)
            ->assertJsonPath('data.block_stats_updated', 2);

        $rows = EloInteraction::orderBy('id')->get();
        // First block in lesson order: unrated, so first contact seeds L = D = 5.
        $this->assertSame(self::BLOCK, $rows[0]->block_id);
        $this->assertSame($this->rating(null), $rows[0]->profil_elo_before);
        $this->assertSame('first_contact', $rows[0]->task_skip_reason);
        // Second block: L before is what the first one left; its D moves.
        $this->assertSame('blk_other', $rows[1]->block_id);
        $this->assertEqualsWithDelta(5.0, $rows[1]->profil_elo_before[self::K], 1e-9);
        $this->assertTrue($rows[1]->task_applied);
        $this->assertSame(0, $this->stat()->item_pocet[self::K]);
        $this->assertSame(1, $this->stat('blk_other')->item_pocet[self::K]);
        $this->assertEqualsWithDelta(
            EloEngine::updateTask($this->rating(5.0), array_fill(0, 35, 0), $this->relation(), $this->rating(6.0), array_fill(0, 35, 0), 1.0)['elo_vector'][self::K],
            $this->stat('blk_other')->elo_vector[self::K],
            1e-12
        );
    }

    public function test_effective_difficulty_prefers_the_calibrated_value_per_dimension(): void
    {
        $this->assertSame(
            [4.5, 6.0, null],
            array_slice(TaskCalibrationService::effective([5, 6, null], [4.5, null, null]), 0, 3)
        );
        $this->assertSame(
            [5.0, null],
            array_slice(TaskCalibrationService::effective([5.0], null), 0, 2)
        );
    }

    private function relation(): array
    {
        $relation = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
        $relation[self::K] = 2;

        return $relation;
    }

    /**
     * Everything the calibration writes: the block rows and the per-answer
     * outcome.
     */
    private function calibrationSnapshot(): array
    {
        return [
            'stats' => BlockStat::orderBy('block_id')->get(['block_id', 'elo_vector', 'item_pocet'])->toArray(),
            'rows' => EloInteraction::orderBy('id')
                ->get(['id', 'task_applied', 'task_skip_reason', 'elo_vector_snapshot'])
                ->toArray(),
        ];
    }
}
