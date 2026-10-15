<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the "Kurz" listing bug: /api/courses/check-updates
 * must include `name` (and `language`) — the app creates local catalog rows
 * from this payload, and without a name they fall back to a generic "Kurz"
 * placeholder that then shows in the course listing.
 *
 * Also covers the timestamp filter: the app sends `since`, older clients send
 * `updated_since`; both must work (previously only `updated_since` was read,
 * so the app always received the full course list).
 */
class CourseCheckUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $courseId, string $name, string $status = 'published'): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => $name,
            'status' => $status,
            'version' => 1,
            'language' => 'cs',
            'data' => ['course_id' => $courseId, 'name' => $name],
        ]);
    }

    public function test_check_updates_includes_name_and_language(): void
    {
        $this->makeCourse('MAT_ZLOMKY', 'Zlomky pro 5. třídu');

        $response = $this->getJson('/api/courses/check-updates');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.course_id', 'MAT_ZLOMKY');
        $response->assertJsonPath('data.0.name', 'Zlomky pro 5. třídu');
        $response->assertJsonPath('data.0.language', 'cs');
    }

    public function test_check_updates_filters_by_since_param(): void
    {
        $old = $this->makeCourse('OLD_COURSE', 'Starý kurz');
        $old->timestamps = false;
        $old->updated_at = now()->subDays(10);
        $old->save();

        $this->makeCourse('NEW_COURSE', 'Nový kurz');

        $response = $this->getJson(
            '/api/courses/check-updates?since='.urlencode(now()->subDay()->toIso8601String())
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course_id', 'NEW_COURSE');
    }

    public function test_check_updates_filters_by_updated_since_param(): void
    {
        $old = $this->makeCourse('OLD_COURSE', 'Starý kurz');
        $old->timestamps = false;
        $old->updated_at = now()->subDays(10);
        $old->save();

        $this->makeCourse('NEW_COURSE', 'Nový kurz');

        $response = $this->getJson(
            '/api/courses/check-updates?updated_since='.urlencode(now()->subDay()->toIso8601String())
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course_id', 'NEW_COURSE');
    }

    public function test_check_updates_hides_unpublished_from_public(): void
    {
        $this->makeCourse('DRAFT_COURSE', 'Rozpracovaný kurz', 'draft');
        $this->makeCourse('LIVE_COURSE', 'Publikovaný kurz');

        $response = $this->getJson('/api/courses/check-updates');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course_id', 'LIVE_COURSE');
    }
}
