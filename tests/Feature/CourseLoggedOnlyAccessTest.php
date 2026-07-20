<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for BR-N2ENN4: a course flagged logged_only must not be
 * downloadable by guest accounts. Guests hold Sanctum tokens, so the
 * auth:sanctum middleware alone let them bypass the client-side "login
 * required" gate — the server must enforce it on the download endpoint.
 */
class CourseLoggedOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(bool $loggedOnly): Course
    {
        return Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'logged_only' => $loggedOnly,
            'file_path' => 'courses/mat_rovnice.json',
            'file_size' => 123,
            'file_uploaded_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_guest_cannot_download_logged_only_course(): void
    {
        $course = $this->makeCourse(loggedOnly: true);
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($guest),
        ])->getJson("/api/courses/{$course->id}/download");

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_courses', [
            'user_id' => $guest->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_real_user_can_download_logged_only_course(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courses/mat_rovnice.json', json_encode(['course_id' => 'MAT_ROVNICE']));

        $course = $this->makeCourse(loggedOnly: true);
        $user = User::factory()->create(['is_guest' => false]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($user),
        ])->getJson("/api/courses/{$course->id}/download?inline=true");

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_courses', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_guest_can_download_public_course(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courses/mat_rovnice.json', json_encode(['course_id' => 'MAT_ROVNICE']));

        $course = $this->makeCourse(loggedOnly: false);
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($guest),
        ])->getJson("/api/courses/{$course->id}/download?inline=true");

        $response->assertStatus(200);
    }
}
