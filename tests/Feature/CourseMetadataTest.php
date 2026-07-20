<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for BR-8SPECV: the course listing must expose
 * denormalized display metadata (lesson_count, estimated_minutes, description,
 * emoji) so not-yet-downloaded courses show their params in the app library.
 * store()/update() must derive that metadata from the course JSON `data`.
 */
class CourseMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function courseData(array $overrides = []): array
    {
        return array_merge([
            'export_type' => 'course_v2',
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'description' => 'Rovnice a nerovnice na úrovni 2. stupně ZŠ.',
            'emoji' => '🔢',
            'lessons' => [
                ['lesson_id' => 'L1'],
                ['lesson_id' => 'L2'],
                ['lesson_id' => 'L3'],
            ],
        ], $overrides);
    }

    public function test_store_denormalizes_display_metadata_from_data(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/courses', [
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(),
        ]);

        $response->assertStatus(201);

        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertSame(3, $course->lesson_count);
        $this->assertSame(45, $course->estimated_minutes); // 3 lessons * 15
        $this->assertSame('Rovnice a nerovnice na úrovni 2. stupně ZŠ.', $course->description);
        $this->assertSame('🔢', $course->emoji);
    }

    public function test_update_recomputes_metadata_when_data_changes(): void
    {
        $course = Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(),
            'lesson_count' => 3,
            'estimated_minutes' => 45,
        ]);

        $response = $this->actingAsAdmin()->putJson("/api/courses/{$course->id}", [
            'data' => $this->courseData([
                'lessons' => [
                    ['lesson_id' => 'L1'],
                    ['lesson_id' => 'L2'],
                    ['lesson_id' => 'L3'],
                    ['lesson_id' => 'L4'],
                    ['lesson_id' => 'L5'],
                ],
            ]),
        ]);

        $response->assertStatus(200);

        $course->refresh();
        $this->assertSame(5, $course->lesson_count);
        $this->assertSame(75, $course->estimated_minutes); // 5 * 15
    }

    public function test_update_does_not_wipe_description_when_data_omits_it(): void
    {
        $course = Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(),
            'description' => 'Puvodni popis',
            'emoji' => '📘',
        ]);

        // New data has no description/emoji keys — existing values must survive.
        $response = $this->actingAsAdmin()->putJson("/api/courses/{$course->id}", [
            'data' => [
                'export_type' => 'course_v2',
                'course_id' => 'MAT_ROVNICE',
                'lessons' => [['lesson_id' => 'L1']],
            ],
        ]);

        $response->assertStatus(200);

        $course->refresh();
        $this->assertSame('Puvodni popis', $course->description);
        $this->assertSame('📘', $course->emoji);
        $this->assertSame(1, $course->lesson_count);
    }
}
