<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Support\CourseBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for BR-XU4Y5K: a course JSON whose `blocks` array lists
 * the same block_id more than once (A2-Rovnice_c1 carried all 16 blocks of one
 * lesson twice) was stored verbatim — in R2 and in `courses.data` — so every
 * export showed each of those blocks twice. Every write path now keeps one
 * copy per block_id: the first, because that is the one the app plays.
 */
class CourseDuplicateBlocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path) => "https://r2.test/{$path}"
        );
    }

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function block(string $id, string $content = 'text'): array
    {
        return [
            'export_type' => 'block_v2',
            'block_id' => $id,
            'type' => 'display',
            'steps' => [['id' => 's1', 'type' => 'text', 'order' => 1, 'content' => $content]],
        ];
    }

    private function courseWithDuplicates(): array
    {
        return [
            'export_type' => 'course_v2',
            'course_id' => 'A2-Rovnice_c1',
            'name' => 'Rovnice a nerovnice',
            'version' => 1,
            'language' => 'cs',
            'status' => 'draft',
            'lessons' => [[
                'lesson_id' => 'L1',
                'order' => 1,
                'blocks' => [
                    ['block_id' => 'A2-rovnice2_01', 'order' => 1],
                    ['block_id' => 'A2-rovnice2_02', 'order' => 2],
                ],
            ]],
            'blocks' => [
                $this->block('A2-rovnice2_01'),
                $this->block('A2-rovnice2_02'),
                $this->block('A2-rovnice2_01'),
                $this->block('A2-rovnice2_02'),
            ],
        ];
    }

    private function blockIds(array $courseData): array
    {
        return array_column($courseData['blocks'], 'block_id');
    }

    public function test_upload_stores_each_block_once_in_r2_and_the_database(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/courses/upload', $this->courseWithDuplicates())
            ->assertStatus(201);

        $course = Course::where('course_id', 'A2-Rovnice_c1')->firstOrFail();
        $this->assertSame(['A2-rovnice2_01', 'A2-rovnice2_02'], $this->blockIds($course->data));

        $stored = json_decode(Storage::disk('s3')->get($course->file_path), true);
        $this->assertSame(['A2-rovnice2_01', 'A2-rovnice2_02'], $this->blockIds($stored));
    }

    public function test_legacy_store_keeps_each_block_once(): void
    {
        $data = $this->courseWithDuplicates();

        $this->actingAsAdmin()->postJson('/api/courses', [
            'name' => $data['name'],
            'course_id' => $data['course_id'],
            'version' => 1,
            'language' => 'cs',
            'data' => $data,
        ])->assertStatus(201);

        $course = Course::where('course_id', 'A2-Rovnice_c1')->firstOrFail();
        $this->assertSame(['A2-rovnice2_01', 'A2-rovnice2_02'], $this->blockIds($course->data));
    }

    public function test_update_with_content_keeps_each_block_once(): void
    {
        $course = Course::create([
            'course_id' => 'A2-Rovnice_c1',
            'name' => 'Rovnice a nerovnice',
            'version' => 1,
            'language' => 'cs',
            'status' => 'draft',
            'data' => ['export_type' => 'course_v2', 'lessons' => [], 'blocks' => []],
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/courses/{$course->id}", ['data' => $this->courseWithDuplicates()])
            ->assertStatus(200);

        $this->assertSame(
            ['A2-rovnice2_01', 'A2-rovnice2_02'],
            $this->blockIds($course->fresh()->data),
        );
    }

    public function test_the_first_copy_wins_when_duplicates_disagree(): void
    {
        $course = CourseBlocks::unique([
            'blocks' => [
                $this->block('B1', 'first'),
                $this->block('B2'),
                $this->block('B1', 'second'),
            ],
        ]);

        $this->assertSame(['B1', 'B2'], $this->blockIds($course));
        $this->assertSame('first', $course['blocks'][0]['steps'][0]['content']);
    }

    public function test_a_course_without_duplicates_is_returned_unchanged(): void
    {
        $data = $this->courseWithDuplicates();
        $data['blocks'] = [$this->block('B1'), $this->block('B2')];

        $this->assertSame($data, CourseBlocks::unique($data));
        $this->assertSame(['name' => 'x'], CourseBlocks::unique(['name' => 'x']));
    }
}
