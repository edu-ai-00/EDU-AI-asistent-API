<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Course;
use App\Models\PracticeCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-952UKZ: the admin's Procvičování panel labelled bookmark/card groups with
 * the raw course_id, because it could only name courses the student is still
 * enrolled in. Rows referencing anything else — a dropped course, or the
 * per-device local Drift row id older app builds pushed — rendered as an
 * internal code. The payload now resolves the name itself.
 */
class AdminUserCourseLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function course(string $courseId, string $name): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => $name,
            'data' => ['lessons' => []],
        ]);
    }

    public function test_bookmarks_and_cards_carry_the_course_name_without_enrollment(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        // The course exists in the catalog, but the student has no user_courses
        // row for it — the old lookup was built from enrollments only.
        $this->course('LITPOJMY_CJ9', 'Literární pojmy k přijímacím zkouškám (ČJ9)');

        Bookmark::create([
            'user_id' => $student->id,
            'course_id' => 'LITPOJMY_CJ9',
            'lesson_id' => 'L1_DRUHY',
            'block_id' => 'L1_B5_uryvek_proza',
        ]);
        PracticeCard::create([
            'user_id' => $student->id,
            'course_id' => 'LITPOJMY_CJ9',
            'lesson_id' => 'L1_DRUHY',
            'block_id' => 'L1_B5_uryvek_proza',
            'source_type' => 'bookmark',
            'state' => 0,
            'due_date' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$student->id}");

        $response->assertOk();
        $this->assertSame(
            'Literární pojmy k přijímacím zkouškám (ČJ9)',
            $response->json('data.bookmarks.0.course_name'),
        );
        $this->assertSame(
            'Literární pojmy k přijímacím zkouškám (ČJ9)',
            $response->json('data.practice_cards.0.course_name'),
        );
    }

    public function test_unresolvable_course_id_yields_a_null_course_name(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        // A local Drift row id pushed by an app build predating the BR-952UKZ
        // fix. Nothing maps it to a course — the admin must be told that
        // explicitly rather than shown the id as if it were a title.
        Bookmark::create([
            'user_id' => $student->id,
            'course_id' => 'local-df5d3cf2-063b-4706-b014-6e99d1e32aa2',
            'lesson_id' => '',
            'block_id' => 'L1_B5_uryvek_proza',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$student->id}");

        $response->assertOk();
        $bookmark = $response->json('data.bookmarks.0');
        $this->assertArrayHasKey('course_name', $bookmark);
        $this->assertNull($bookmark['course_name']);
    }
}
