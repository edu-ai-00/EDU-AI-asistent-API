<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for BR-JUWYK2: two authors editing one course must not
 * silently overwrite each other. The editor saves the whole course, so it
 * tells the API which version its copy was loaded from; a save based on a
 * copy the course has since moved past is refused with a 409.
 */
class CourseEditConflictTest extends TestCase
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
            'version' => 23,
            'language' => 'cs',
            'status' => 'draft',
            'lessons' => [['lesson_id' => 'L1', 'name' => 'Lekce 1']],
            'blocks' => [],
        ], $overrides);
    }

    private function existingCourse(int $version = 23, string $status = 'draft'): Course
    {
        $data = $this->payload(['version' => $version, 'status' => $status]);
        $path = Course::generateFilePath('MAT_ROVNICE', $version);
        Storage::disk('s3')->put($path, json_encode($data));

        return Course::create([
            'course_id' => 'MAT_ROVNICE',
            'name' => 'Kurz',
            'version' => $version,
            'language' => 'cs',
            'status' => $status,
            'data' => $data,
            'file_path' => $path,
        ]);
    }

    /** An editor save: whole course, server-side versioning, plus its base. */
    private function editorSave(int $baseVersion, array $overrides = [])
    {
        return $this->actingAsAdmin()->postJson('/api/courses/upload', $this->payload(array_merge([
            'auto_version' => true,
            'base_version' => $baseVersion,
        ], $overrides)));
    }

    public function test_a_save_from_a_copy_someone_else_has_saved_over_is_refused(): void
    {
        $this->existingCourse(23);

        // Both authors opened v23. Author A saves first.
        $this->editorSave(23, ['name' => 'Úprava autora A'])->assertStatus(200);

        // Author B still edits the v23 copy and saves over it.
        $response = $this->editorSave(23, ['name' => 'Úprava autora B']);

        $response->assertStatus(409);
        $response->assertJsonPath('edit_conflict.base_version', 23);
        $response->assertJsonPath('edit_conflict.current_version', 24);
        $this->assertStringContainsString('upravil někdo jiný', $response->json('message'));

        // Author A's save is intact — in the database and in R2.
        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertSame(24, $course->version);
        $this->assertSame('Úprava autora A', $course->name);
        $this->assertSame('Úprava autora A', $course->data['name']);
        $this->assertFalse(Storage::disk('s3')->exists('courses/MAT_ROVNICE/v25.json'));
        $stored = json_decode(Storage::disk('s3')->get($course->file_path), true);
        $this->assertSame('Úprava autora A', $stored['name']);
    }

    public function test_an_editor_saving_twice_in_a_row_moves_its_base_along(): void
    {
        $this->existingCourse(23);

        $first = $this->editorSave(23, ['name' => 'První uložení']);
        $first->assertStatus(200);
        $first->assertJsonPath('data.version', 24);

        $this->editorSave(24, ['name' => 'Druhé uložení'])
            ->assertStatus(200)
            ->assertJsonPath('data.version', 25);

        $this->assertSame('Druhé uložení', Course::where('course_id', 'MAT_ROVNICE')->firstOrFail()->name);
    }

    public function test_a_status_change_from_the_course_list_is_not_reverted_by_an_open_editor(): void
    {
        $course = $this->existingCourse(23, 'draft');

        // The editor loaded the v23 draft; meanwhile "Změnit stav" publishes
        // it, which moves the course to v24.
        $this->actingAsAdmin()
            ->putJson("/api/courses/{$course->id}", ['status' => 'published'])
            ->assertStatus(200);

        // The editor's copy still says draft.
        $response = $this->editorSave(23, ['status' => 'draft']);

        $response->assertStatus(409);
        $response->assertJsonPath('edit_conflict.current_version', 24);
        $response->assertJsonPath('edit_conflict.current_status', 'published');
        $this->assertSame('published', $course->fresh()->status);
    }

    public function test_an_editor_loaded_after_the_status_change_saves_normally(): void
    {
        $course = $this->existingCourse(23, 'draft');
        $this->actingAsAdmin()
            ->putJson("/api/courses/{$course->id}", ['status' => 'published'])
            ->assertStatus(200);

        // The status change touched only the database row; the stored JSON
        // still says draft. The download the editor loads from reports the
        // live status, which the editor opens with instead.
        $this->actingAsAdmin()
            ->getJson("/api/admin/courses/{$course->id}/download")
            ->assertStatus(200)
            ->assertJsonPath('data.version', 24)
            ->assertJsonPath('data.status', 'published');

        $this->editorSave(24, ['status' => 'published'])
            ->assertStatus(200)
            ->assertJsonPath('data.version', 25);

        $this->assertSame('published', $course->fresh()->status);
    }

    public function test_the_base_is_never_persisted_into_the_course_json(): void
    {
        $this->existingCourse(23);

        $this->editorSave(23)->assertStatus(200);

        $course = Course::where('course_id', 'MAT_ROVNICE')->firstOrFail();
        $this->assertArrayNotHasKey('base_version', $course->data);

        $stored = json_decode(Storage::disk('s3')->get($course->file_path), true);
        $this->assertArrayNotHasKey('base_version', $stored);
    }

    public function test_a_new_course_ignores_the_base(): void
    {
        $this->editorSave(1, ['version' => 1])
            ->assertStatus(201)
            ->assertJsonPath('data.version', 1);
    }
}
