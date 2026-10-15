<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for BR-YKYXC6: the cover set in admin ("Header Image
 * (Course Cover)") lives in the course JSON, and the `data` column is hidden
 * from listing payloads. Without a dedicated listing field the app could only
 * show a cover for courses it had already downloaded, so library cards kept
 * falling back to the emoji.
 */
class CourseCoverImageTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(?array $headerImage): Course
    {
        return Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'data' => array_merge([
                'export_type' => 'course_v2',
                'course_id' => 'MAT_ROVNICE',
                'name' => 'Kurz',
                'emoji' => '🔢',
            ], $headerImage === null ? [] : ['header_image' => $headerImage]),
        ]);
    }

    public function test_listing_exposes_course_cover(): void
    {
        $this->makeCourse([
            'url' => 'https://cdn.example.com/covers/rovnice.png',
            'alt' => 'Rovnice',
        ]);

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.header_image.url', 'https://cdn.example.com/covers/rovnice.png');
        $response->assertJsonPath('data.0.header_image.alt', 'Rovnice');
        // The full course JSON still stays out of the listing.
        $response->assertJsonMissingPath('data.0.data');
    }

    public function test_listing_cover_is_null_without_one(): void
    {
        $this->makeCourse(null);

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.header_image', null);
    }

    public function test_listing_cover_is_null_when_url_is_missing(): void
    {
        $this->makeCourse(['alt' => 'Bez URL']);

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.header_image', null);
    }

    public function test_check_updates_still_works_without_the_data_column(): void
    {
        // checkUpdates selects a column subset that excludes `data`; the
        // appended cover must not explode there.
        $this->makeCourse(['url' => 'https://cdn.example.com/covers/rovnice.png']);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        ])->getJson('/api/courses/check-updates');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.header_image', null);
    }
}
