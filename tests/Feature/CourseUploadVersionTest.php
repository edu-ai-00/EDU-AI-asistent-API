<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for BR-PKYUZC: the admin course editor must not be able
 * to lock itself out of saving because its local copy of `version` fell behind
 * the server. Editor saves opt into server-side versioning with
 * `auto_version: true`; plain JSON imports keep the strict version check.
 */
class CourseUploadVersionTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'export_type' => 'course_v2',
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'draft',
            'lessons' => [['lesson_id' => 'L1']],
            'blocks' => [],
        ], $overrides);
    }

    private function existingCourseAtVersion(int $version): Course
    {
        return Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => $version,
            'language' => 'cs',
            'status' => 'draft',
            'data' => $this->payload(['version' => $version]),
        ]);
    }

    public function test_import_without_auto_version_still_rejects_a_stale_version(): void
    {
        $this->existingCourseAtVersion(23);

        $response = $this->actingAsAdmin()
            ->postJson('/api/courses/upload', $this->payload(['version' => 23]));

        $response->assertStatus(422);
        $response->assertJsonPath('current_version', 23);
        $response->assertJsonPath('provided_version', 23);

        $this->assertSame(23, Course::where('course_id', 'MAT_ROVNICE')->firstOrFail()->version);
    }

    public function test_auto_version_saves_a_stale_editor_payload_as_the_next_version(): void
    {
        $this->existingCourseAtVersion(23);

        // The editor still believes the course is at v22 and offers "22 → 23".
        $response = $this->actingAsAdmin()->postJson('/api/courses/upload', $this->payload([
            'version' => 23,
            'auto_version' => true,
            'quiz_evaluate' => true,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.version', 24);

        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertSame(24, $course->version);
        $this->assertTrue($course->quiz_evaluate);
    }

    public function test_auto_version_flag_is_not_persisted_into_the_course_json(): void
    {
        $this->existingCourseAtVersion(23);

        $this->actingAsAdmin()->postJson('/api/courses/upload', $this->payload([
            'version' => 23,
            'auto_version' => true,
        ]))->assertStatus(200);

        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertArrayNotHasKey('auto_version', $course->data);
        $this->assertSame(24, $course->data['version']);

        $stored = json_decode(Storage::disk('s3')->get($course->file_path), true);
        $this->assertArrayNotHasKey('auto_version', $stored);
        $this->assertSame(24, $stored['version']);
    }

    public function test_auto_version_keeps_the_requested_version_for_a_brand_new_course(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/courses/upload', $this->payload([
            'version' => 1,
            'auto_version' => true,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.version', 1);
    }
}
