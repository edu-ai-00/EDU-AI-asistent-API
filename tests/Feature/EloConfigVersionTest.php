<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserProgress;
use App\Services\EloEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every ELO interaction records the engine configuration that produced its
 * snapshots (BR-EBNMBF). Pilot 2 data has to be separable by configuration,
 * but elo_interactions had nowhere to keep the label: the app computes the
 * update, so the app reports its version; the admin backfill replays with
 * the PHP engine, so it stamps EloEngine::CONFIG_VERSION. Rows written
 * before the column existed — or by an app build that does not send it —
 * stay empty rather than being guessed.
 */
class EloConfigVersionTest extends TestCase
{
    use RefreshDatabase;

    private const EXPORT = '/api/admin/elo/interactions/course/course-v/export';

    private const VERSION_HEADER = 'Verze ELO';

    private function interactionPayload(array $overrides = []): array
    {
        return array_merge([
            'block_id' => 'blk_1',
            'course_id' => 'course-v',
            'source' => 'lesson',
            'score' => 1.0,
        ], $overrides);
    }

    public function test_single_interaction_stores_the_version_the_app_sends(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/elo/interactions', $this->interactionPayload([
            'elo_config_version' => 'pilot2-elo-v1',
        ]))->assertCreated();

        $this->assertSame('pilot2-elo-v1', EloInteraction::sole()->elo_config_version);
    }

    public function test_batch_stores_the_version_per_interaction(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/elo/interactions/batch', [
            'interactions' => [
                $this->interactionPayload(['block_id' => 'blk_lesson', 'elo_config_version' => 'pilot2-elo-v1']),
                $this->interactionPayload(['block_id' => 'blk_quiz', 'source' => 'quiz', 'elo_config_version' => 'pilot2-elo-v1']),
            ],
        ])->assertOk();

        $this->assertSame(
            ['blk_lesson' => 'pilot2-elo-v1', 'blk_quiz' => 'pilot2-elo-v1'],
            EloInteraction::pluck('elo_config_version', 'block_id')->all()
        );
    }

    public function test_app_build_without_a_version_leaves_it_empty(): void
    {
        // An older app build computed its snapshot with whatever engine it
        // shipped; stamping the server's version on it would mislabel it.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/elo/interactions', $this->interactionPayload())->assertCreated();

        $this->assertNull(EloInteraction::sole()->elo_config_version);
    }

    public function test_backfill_stamps_the_server_engine_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $relation = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
        $relation[0] = 2;

        Course::create([
            'course_id' => 'course-v',
            'name' => 'Course V',
            'status' => 'published',
            'data' => [
                'course_id' => 'course-v',
                'blocks' => [[
                    'block_id' => 'blk_1',
                    'gpf' => [
                        'relation_vector' => $relation,
                        'elo_vector' => array_fill(0, EloEngine::GPF_DIMENSIONS, 6.0),
                    ],
                ]],
                'lessons' => [[
                    'order' => 1,
                    'blocks' => [['block_id' => 'blk_1', 'order' => 1]],
                ]],
            ],
        ]);

        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => 'course-v',
            'lesson_id' => 'lesson-1',
            'progress_data' => [
                'blocks' => ['blk_1' => ['isCompleted' => true, 'isCorrect' => true]],
            ],
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/elo/backfill/run/course-v')
            ->assertOk()
            ->assertJsonPath('data.interactions_created', 1);

        $this->assertSame(EloEngine::CONFIG_VERSION, EloInteraction::sole()->elo_config_version);
    }

    public function test_export_appends_the_version_as_the_last_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'course_id' => 'course-v',
            'name' => 'Course V',
            'status' => 'published',
            'data' => ['blocks' => []],
        ]);

        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_new',
            'course_id' => 'course-v',
            'source' => 'lesson',
            'score' => 1.0,
            'elo_config_version' => 'pilot2-elo-v1',
        ]);
        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_legacy',
            'course_id' => 'course-v',
            'source' => 'quiz',
            'score' => 0.0,
        ]);

        // A content block the student only traversed: no ELO row, no version.
        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_text' => ['opened_at' => '2026-09-26T08:00:00Z', 'confirmed_at' => '2026-09-26T08:00:30Z'],
                    ],
                ]],
            ],
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::EXPORT);

        $header = $rows[0];
        $this->assertSame(self::VERSION_HEADER, end($header));

        $versions = [];
        foreach (array_slice($rows, 1) as $row) {
            $this->assertCount(count($header), $row);
            $versions[$row[2]] = end($row);
        }

        $this->assertSame(
            ['blk_new' => 'pilot2-elo-v1', 'blk_legacy' => '', 'blk_text' => ''],
            $versions
        );
    }

    /**
     * Parse the streamed TSV export into field arrays.
     *
     * @return list<list<string>>
     */
    private function exportRows(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        $content = ltrim($response->streamedContent(), "\xEF\xBB\xBF");
        $lines = array_filter(
            explode("\n", rtrim($content, "\r\n")),
            fn (string $l) => trim($l) !== ''
        );

        return array_values(array_map(
            fn (string $line) => array_map('strval', str_getcsv(rtrim($line, "\r"), "\t", '"', '\\')),
            $lines
        ));
    }
}
