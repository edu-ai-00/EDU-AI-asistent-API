<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan courses:check-gpf-vectors` — the one-off audit of GPF vectors
 * already stored in course content (BR-S9SVNM). It lists what the app's ELO
 * engine would reject and must never change a course.
 */
class CheckCourseGpfVectorsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $courseId, array $blocks): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => "Kurz {$courseId}",
            'status' => 'published',
            'version' => 3,
            'language' => 'cs',
            'data' => [
                'course_id' => $courseId,
                'name' => "Kurz {$courseId}",
                'lessons' => [[
                    'name' => 'Lekce 1',
                    'blocks' => array_map(fn ($b) => ['block_id' => $b['block_id']], $blocks),
                ]],
                'blocks' => $blocks,
            ],
        ]);
    }

    private function gpfBlock(string $blockId, array $relationOverrides = [], array $eloOverrides = []): array
    {
        return [
            'block_id' => $blockId,
            'gpf' => [
                'relation_vector' => array_replace(array_fill(0, 35, 0), $relationOverrides),
                'elo_vector' => array_replace(array_fill(0, 35, 5.0), $eloOverrides),
            ],
        ];
    }

    public function test_succeeds_when_every_vector_is_valid(): void
    {
        $this->makeCourse('GOOD', [$this->gpfBlock('B1', [3 => 2])]);

        $this->artisan('courses:check-gpf-vectors')
            ->expectsOutputToContain('Checked 1 course(s): no invalid GPF vectors found.')
            ->assertSuccessful();
    }

    public function test_lists_each_invalid_block_and_fails(): void
    {
        $this->makeCourse('GOOD', [$this->gpfBlock('G1', [3 => 2])]);
        $bad = $this->makeCourse('BAD', [
            $this->gpfBlock('B1', [3 => 1.5]),
            $this->gpfBlock('B2', [], [0 => 12]),
            $this->gpfBlock('B3', [4 => 2]),
        ]);

        $this->artisan('courses:check-gpf-vectors')
            ->expectsOutputToContain("Course #{$bad->id} BAD v3")
            ->expectsOutputToContain('B1 [Lekce 1]: relation_vector: invalid values [3] = 1.5')
            ->expectsOutputToContain('B2 [Lekce 1]: elo_vector: invalid values [0] = 12')
            ->expectsOutputToContain('Checked 2 course(s): 2 problem(s) in 2 block(s) across 1 course(s). Nothing was changed.')
            ->assertFailed();
    }

    public function test_course_option_limits_the_check(): void
    {
        $this->makeCourse('BAD', [$this->gpfBlock('B1', [3 => 1.5])]);
        $this->makeCourse('GOOD', [$this->gpfBlock('G1')]);

        $this->artisan('courses:check-gpf-vectors', ['--course' => 'GOOD'])
            ->expectsOutputToContain('Checked 1 course(s): no invalid GPF vectors found.')
            ->assertSuccessful();
    }

    public function test_never_changes_a_course(): void
    {
        $course = $this->makeCourse('BAD', [$this->gpfBlock('B1', [3 => 1.5])]);
        $before = Course::find($course->id)->getAttributes();

        $this->travel(5)->minutes();
        $this->artisan('courses:check-gpf-vectors')->assertFailed();

        $this->assertSame($before, Course::find($course->id)->getAttributes());
    }
}
