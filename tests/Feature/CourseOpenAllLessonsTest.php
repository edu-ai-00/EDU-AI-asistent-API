<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-K47WYE: the `open_all_lessons` course setting lives in the course JSON,
 * and is denormalized into a column so the listing can serve it without
 * decoding the full `data` blob. The app reads it from the listing to unlock
 * lessons on already-downloaded courses.
 */
class CourseOpenAllLessonsTest extends TestCase
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
            'lessons' => [
                ['lesson_id' => 'L1'],
                ['lesson_id' => 'L2'],
            ],
        ], $overrides);
    }

    public function test_store_denormalizes_open_all_lessons_from_data(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/courses', [
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(['open_all_lessons' => true]),
        ]);

        $response->assertStatus(201);

        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertTrue($course->open_all_lessons);
    }

    public function test_flag_defaults_to_false_when_data_omits_it(): void
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
        $this->assertFalse($course->open_all_lessons);
    }

    public function test_update_flips_the_flag_both_ways(): void
    {
        $course = Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(),
        ]);

        $this->actingAsAdmin()->putJson("/api/courses/{$course->id}", [
            'data' => $this->courseData(['open_all_lessons' => true]),
        ])->assertStatus(200);

        $course->refresh();
        $this->assertTrue($course->open_all_lessons);

        $this->actingAsAdmin()->putJson("/api/courses/{$course->id}", [
            'data' => $this->courseData(['open_all_lessons' => false]),
        ])->assertStatus(200);

        $course->refresh();
        $this->assertFalse($course->open_all_lessons);
    }

    public function test_listing_exposes_the_flag_without_the_data_blob(): void
    {
        Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => $this->courseData(['open_all_lessons' => true]),
            'open_all_lessons' => true,
        ]);

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200);
        $course = collect($response->json('data'))
            ->firstWhere('course_id', 'MAT_ROVNICE');

        $this->assertNotNull($course);
        $this->assertTrue($course['open_all_lessons']);
        // The whole point of the column: no JSON decode needed downstream.
        $this->assertArrayNotHasKey('data', $course);
    }
}
