<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-9F9VV9: assigning a course to a class showed every pupil in the admin
 * user list as active "před 18 min", although none of them had logged in.
 * The assignment writes a user_courses placeholder per pupil (status
 * downloaded, downloaded_version 0, downloaded_at now), and the list took
 * MAX(user_courses.updated_at) as the pupil's activity. A placeholder the
 * pupil never touched is not their activity — only what they do with it is.
 */
class AdminUserLastActiveTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::create([
            'course_id' => 'ZLOMKY_5',
            'name' => 'Zlomky',
            'version' => 3,
            'file_path' => 'courses/ZLOMKY_5/v3.json',
            'data' => ['lessons' => []],
        ]);
    }

    private function lastActiveOf(User $student): ?string
    {
        $row = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))
            ->firstWhere('id', $student->id);

        $this->assertNotNull($row, 'student missing from the admin user list');

        return $row['last_active_at'];
    }

    /** A class with one pupil, the course assigned to it by an admin at $at. */
    private function assignedAt(Carbon $at): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $classroom = Classroom::create(['name' => '6.A']);
        $student->classrooms()->attach($classroom->id);
        $course = $this->course();

        $this->travelTo($at);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/classrooms/{$classroom->id}/courses", ['course_ids' => [$course->id]])
            ->assertOk();

        return [$admin, $student, $course];
    }

    public function test_assigning_a_course_to_a_class_is_no_pupil_activity(): void
    {
        [, $student] = $this->assignedAt(Carbon::parse('2026-10-14 10:00:00', 'UTC'));

        $this->travel(18)->minutes();

        $this->assertNull($this->lastActiveOf($student));

        // Nor did the pupil download it — the detail must not say "Staženo".
        $courses = $this->getJson("/api/admin/users/{$student->id}")->assertOk()->json('data.courses');
        $this->assertCount(1, $courses);
        $this->assertNull($courses[0]['downloaded_at']);
    }

    public function test_the_pupils_own_download_of_an_assigned_course_is_activity(): void
    {
        [$admin, $student, $course] = $this->assignedAt(Carbon::parse('2026-10-14 10:00:00', 'UTC'));

        Storage::fake('s3');
        Storage::disk('s3')->put($course->file_path, json_encode(['lessons' => []]));

        $this->travelTo(Carbon::parse('2026-10-14 12:30:00', 'UTC'));
        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}/download?inline=true")->assertOk();

        Sanctum::actingAs($admin);
        $this->assertSame('2026-10-14T12:30:00+00:00', $this->lastActiveOf($student));

        $courses = $this->getJson("/api/admin/users/{$student->id}")->assertOk()->json('data.courses');
        $this->assertSame('2026-10-14T12:30:00+00:00', $courses[0]['downloaded_at']);
    }

    public function test_progress_on_an_assigned_course_is_activity(): void
    {
        [$admin, $student, $course] = $this->assignedAt(Carbon::parse('2026-10-14 10:00:00', 'UTC'));

        $this->travelTo(Carbon::parse('2026-10-14 13:15:00', 'UTC'));
        Sanctum::actingAs($student);
        $this->putJson("/api/user/courses/{$course->course_id}", [
            'status' => 'in_progress',
            'progress_percent' => 10,
        ])->assertOk();

        Sanctum::actingAs($admin);
        $this->assertSame('2026-10-14T13:15:00+00:00', $this->lastActiveOf($student));
    }

    public function test_a_course_the_pupil_added_themself_is_activity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course();

        // Self-added rows carry no downloaded_version at all (NULL, not 0).
        $this->travelTo(Carbon::parse('2026-10-14 09:05:00', 'UTC'));
        Sanctum::actingAs($student);
        $this->postJson('/api/user/courses', ['course_id' => $course->course_id])->assertCreated();

        Sanctum::actingAs($admin);
        $this->assertSame('2026-10-14T09:05:00+00:00', $this->lastActiveOf($student));
    }
}
