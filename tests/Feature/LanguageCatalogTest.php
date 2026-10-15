<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BR-AYACEK — the set of languages a course may be published in lives in the
 * database, not in a free-text field. Everything that references a language
 * references this catalogue.
 */
class LanguageCatalogTest extends TestCase
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

    public function test_the_catalogue_is_seeded_with_czech_and_english(): void
    {
        $this->assertDatabaseHas('languages', ['code' => 'cs']);
        $this->assertDatabaseHas('languages', ['code' => 'en']);
    }

    public function test_languages_are_listed_publicly_in_sort_order(): void
    {
        $response = $this->getJson('/api/languages');

        $response->assertStatus(200);
        $codes = array_column($response->json('data'), 'code');

        $this->assertSame(['cs', 'en'], array_slice($codes, 0, 2));
        $this->assertArrayHasKey('native_name', $response->json('data.0'));
    }

    public function test_inactive_languages_are_hidden_from_the_public_list(): void
    {
        Language::create([
            'code' => 'zz',
            'name' => 'Testish',
            'native_name' => 'Testish',
            'is_active' => false,
            'sort_order' => 99,
        ]);

        $codes = array_column($this->getJson('/api/languages')->json('data'), 'code');

        $this->assertNotContains('zz', $codes);
    }

    public function test_uploading_a_course_in_an_unknown_language_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/courses/upload', [
            'export_type' => 'course_v2',
            'course_id' => 'MAT_1',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'klingon',
            'lessons' => [],
            'blocks' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('language');
    }

    public function test_uploading_a_course_in_a_catalogued_language_is_accepted(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/courses/upload', [
            'export_type' => 'course_v2',
            'course_id' => 'MAT_1',
            'name' => 'Kurz',
            'version' => 1,
            'language' => 'cs',
            'lessons' => [],
            'blocks' => [],
        ]);

        $response->assertStatus(201);
    }

    public function test_an_admin_can_add_a_language_to_the_catalogue(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/admin/languages', [
            'code' => 'hu',
            'name' => 'Hungarian',
            'native_name' => 'Magyar',
            'sort_order' => 7,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('languages', ['code' => 'hu', 'is_active' => true]);
    }

    public function test_an_admin_can_deactivate_a_language(): void
    {
        $this->actingAsAdmin()
            ->putJson('/api/admin/languages/en', ['is_active' => false])
            ->assertStatus(200);

        $this->assertFalse(Language::where('code', 'en')->firstOrFail()->is_active);
    }

    public function test_a_non_admin_cannot_change_the_catalogue(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/languages', [
                'code' => 'de',
                'name' => 'German',
                'native_name' => 'Deutsch',
            ])
            ->assertStatus(403);
    }
}
