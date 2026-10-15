<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for BR-TBN2T6: in the admin Files section, deleting an image and
 * uploading a new one under the same name kept showing the old picture — in
 * the folder and in every lesson that used it.
 *
 * Replacing works by reusing the URL: lessons reference assets by the URL
 * copied out of the Files section, so the new upload lands on the same R2 key.
 * The objects were stored without a Cache-Control header, though, so browsers
 * cached them heuristically (for a slice of the file's age — days to weeks)
 * and never asked R2 again. Uploads now carry `Cache-Control: no-cache`, so
 * every view revalidates against the ETag and a replaced file shows up.
 */
class AssetReplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['filesystems.disks.s3.url' => 'https://cdn.test']);

        Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'status' => 'published',
            'file_path' => 'courses/mat_rovnice.json',
            'file_size' => 123,
            'file_uploaded_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function test_upload_tells_caches_to_revalidate_the_file(): void
    {
        $real = Storage::disk('s3');
        $captured = null;

        $disk = Mockery::mock($real);
        $disk->shouldReceive('put')
            ->once()
            ->andReturnUsing(function ($path, $contents, $options = []) use ($real, &$captured) {
                $captured = $options;

                return $real->put($path, $contents, $options);
            });
        Storage::set('s3', $disk);

        $response = $this->actingAsAdmin()->post('/api/admin/assets/upload', [
            'course_id' => 'MAT_ROVNICE',
            'file' => UploadedFile::fake()->image('graf.png'),
        ]);

        $response->assertStatus(201);
        $this->assertSame('no-cache', $captured['CacheControl'] ?? null);
        $this->assertSame('image/png', $captured['ContentType'] ?? null);
    }

    public function test_reuploading_a_deleted_file_keeps_its_url(): void
    {
        $first = $this->actingAsAdmin()->post('/api/admin/assets/upload', [
            'course_id' => 'MAT_ROVNICE',
            'file' => UploadedFile::fake()->image('graf.png', 10, 10),
        ]);
        $first->assertStatus(201);

        $this->actingAsAdmin()
            ->deleteJson('/api/admin/assets/' . $first->json('data.id'))
            ->assertStatus(200);
        Storage::disk('s3')->assertMissing('assets/MAT_ROVNICE/graf.png');

        $replacement = UploadedFile::fake()->image('graf.png', 20, 20);
        $second = $this->actingAsAdmin()->post('/api/admin/assets/upload', [
            'course_id' => 'MAT_ROVNICE',
            'file' => $replacement,
        ]);

        $second->assertStatus(201);
        // Same URL, so lessons pointing at it pick up the new file.
        $this->assertSame($first->json('data.url'), $second->json('data.url'));
        $this->assertSame('https://cdn.test/assets/MAT_ROVNICE/graf.png', $second->json('data.url'));
        $this->assertSame(
            file_get_contents($replacement->getRealPath()),
            Storage::disk('s3')->get('assets/MAT_ROVNICE/graf.png'),
        );
    }
}
