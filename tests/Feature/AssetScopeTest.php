<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage for BR-KPXQA8: the admin Files section only ever offered course
 * folders, because assets were hard-bound to a course. Assets can now also
 * live in a scoped, course-less folder — currently just "news" — so editors
 * have somewhere to put images and videos for news items.
 */
class AssetScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['filesystems.disks.s3.url' => 'https://cdn.test']);
    }

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function makeCourse(string $courseId = 'MAT_ROVNICE'): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'file_path' => 'courses/mat_rovnice.json',
            'file_size' => 123,
            'file_uploaded_at' => now(),
        ]);
    }

    public function test_uploads_an_image_to_the_news_scope_without_a_course(): void
    {
        $response = $this->actingAsAdmin()->post('/api/admin/assets/upload', [
            'scope' => 'news',
            'file' => UploadedFile::fake()->image('novinka.png'),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.scope', 'news');
        $response->assertJsonPath('data.course_id', null);
        $this->assertSame('assets/news/novinka.png', $response->json('data.path'));
        Storage::disk('s3')->assertExists('assets/news/novinka.png');
    }

    public function test_uploads_a_video_to_the_news_scope(): void
    {
        $response = $this->actingAsAdmin()->post('/api/admin/assets/upload', [
            'scope' => 'news',
            'file' => UploadedFile::fake()->create('klip.mp4', 128, 'video/mp4'),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.scope', 'news');
        Storage::disk('s3')->assertExists('assets/news/klip.mp4');
    }

    public function test_news_listing_excludes_course_assets(): void
    {
        $course = $this->makeCourse();

        $newsAsset = Asset::create([
            'scope' => 'news',
            'course_id' => null,
            'filename' => 'novinka.png',
            'path' => 'assets/news/novinka.png',
            'url' => 'https://cdn.test/assets/news/novinka.png',
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        Asset::create([
            'course_id' => $course->course_id,
            'filename' => 'kurz.png',
            'path' => "assets/{$course->course_id}/kurz.png",
            'url' => "https://cdn.test/assets/{$course->course_id}/kurz.png",
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/admin/assets?scope=news');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newsAsset->id], $ids);
    }

    public function test_course_listing_excludes_news_assets(): void
    {
        $course = $this->makeCourse();

        Asset::create([
            'scope' => 'news',
            'course_id' => null,
            'filename' => 'novinka.png',
            'path' => 'assets/news/novinka.png',
            'url' => 'https://cdn.test/assets/news/novinka.png',
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        $courseAsset = Asset::create([
            'course_id' => $course->course_id,
            'filename' => 'kurz.png',
            'path' => "assets/{$course->course_id}/kurz.png",
            'url' => "https://cdn.test/assets/{$course->course_id}/kurz.png",
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        $response = $this->actingAsAdmin()->getJson("/api/admin/assets?course_id={$course->course_id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$courseAsset->id], $ids);
    }

    public function test_upload_without_course_id_or_scope_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/admin/assets/upload', [
            'file' => UploadedFile::fake()->image('novinka.png'),
        ]);

        $response->assertStatus(422);
    }

    public function test_unknown_scope_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/admin/assets/upload', [
            'scope' => 'random-folder',
            'file' => UploadedFile::fake()->image('novinka.png'),
        ]);

        $response->assertStatus(422);
    }

    public function test_deleting_a_course_leaves_news_assets_untouched(): void
    {
        $course = $this->makeCourse();

        $newsAsset = Asset::create([
            'scope' => 'news',
            'course_id' => null,
            'filename' => 'novinka.png',
            'path' => 'assets/news/novinka.png',
            'url' => 'https://cdn.test/assets/news/novinka.png',
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        Asset::create([
            'course_id' => $course->course_id,
            'filename' => 'kurz.png',
            'path' => "assets/{$course->course_id}/kurz.png",
            'url' => "https://cdn.test/assets/{$course->course_id}/kurz.png",
            'size' => 10,
            'mime_type' => 'image/png',
        ]);

        $course->forceDelete();

        $this->assertDatabaseHas('assets', ['id' => $newsAsset->id]);
        $this->assertDatabaseCount('assets', 1);
    }
}
