<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BR-AYACEK — language variants of a course form a translation group that must
 * stay structurally identical.
 *
 * A variant carries its own course_id (so pins, progress, practice cards and
 * quiz attempts all keep working unchanged) and a shared `translation_key`. On
 * upload the server fingerprints the structure and refuses the write when it
 * disagrees with a sibling, naming what diverged.
 */
class CourseTranslationTest extends TestCase
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

    private function czech(array $overrides = []): array
    {
        return array_merge([
            'export_type' => 'course_v2',
            'course_id' => 'MAT_ZLOMKY',
            'translation_key' => 'MAT_ZLOMKY',
            'name' => 'Zlomky',
            'version' => 1,
            'language' => 'cs',
            'status' => 'draft',
            'lessons' => [[
                'lesson_id' => 'L1',
                'name' => 'Úvod',
                'order' => 1,
                'blocks' => [['block_id' => 'B1', 'order' => 1]],
            ]],
            'blocks' => [[
                'export_type' => 'block_v2',
                'block_id' => 'B1',
                'type' => 'question',
                'duration' => '2m',
                'gpf' => ['domain' => 'Number', 'construct' => 'N2', 'grade' => 5, 'level' => 3],
                'learning' => ['concepts' => ['Zlomky'], 'bloom_level' => 2, 'difficulty' => 3],
                'steps' => [[
                    'id' => 's1',
                    'type' => 'question',
                    'order' => 1,
                    'content' => 'Kolik je 1/2 + 1/2?',
                    'question' => [
                        'type' => 'multiple_choice',
                        'options' => [
                            ['id' => 'o1', 'text' => 'Jedna', 'is_correct' => true],
                            ['id' => 'o2', 'text' => 'Dvě', 'is_correct' => false],
                        ],
                    ],
                ]],
            ]],
        ], $overrides);
    }

    /** Same logic, translated prose, own id and pin. */
    private function english(array $overrides = []): array
    {
        $course = $this->czech();

        $course['course_id'] = 'MAT_ZLOMKY_EN';
        $course['name'] = 'Fractions';
        $course['language'] = 'en';
        $course['lessons'][0]['name'] = 'Introduction';
        $course['blocks'][0]['steps'][0]['content'] = 'What is 1/2 + 1/2?';
        $course['blocks'][0]['steps'][0]['question']['options'][0]['text'] = 'One';
        $course['blocks'][0]['steps'][0]['question']['options'][1]['text'] = 'Two';

        return array_merge($course, $overrides);
    }

    private function upload(array $payload)
    {
        return $this->actingAsAdmin()->postJson('/api/courses/upload', $payload);
    }

    public function test_a_new_course_becomes_its_own_translation_group(): void
    {
        $this->upload($this->czech(['translation_key' => null]))->assertStatus(201);

        $course = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $this->assertSame('MAT_ZLOMKY', $course->translation_key);
        $this->assertNotNull($course->structure_hash);
    }

    public function test_a_faithful_translation_joins_the_group(): void
    {
        $this->upload($this->czech())->assertStatus(201);

        $response = $this->upload($this->english());

        $response->assertStatus(201);
        $response->assertJsonPath('data.translation_key', 'MAT_ZLOMKY');
        $response->assertJsonPath('data.language', 'en');

        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();
        $english = Course::where('course_id', 'MAT_ZLOMKY_EN')->firstOrFail();

        $this->assertSame($czech->structure_hash, $english->structure_hash);
        $this->assertSame(2, Course::where('translation_key', 'MAT_ZLOMKY')->count());
    }

    public function test_a_translation_that_changes_the_structure_is_refused(): void
    {
        $this->upload($this->czech())->assertStatus(201);

        $divergent = $this->english();
        $divergent['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Three', 'is_correct' => false,
        ];

        $response = $this->upload($divergent);

        $response->assertStatus(422);
        $response->assertJsonPath('structure_conflict.against.course_id', 'MAT_ZLOMKY');
        $response->assertJsonPath('structure_conflict.against.language', 'cs');
        $this->assertNotEmpty($response->json('structure_conflict.diff'));
        $this->assertStringContainsString(
            'B1',
            implode("\n", $response->json('structure_conflict.diff')),
        );
    }

    public function test_a_refused_translation_is_not_written_anywhere(): void
    {
        $this->upload($this->czech())->assertStatus(201);

        $divergent = $this->english();
        array_pop($divergent['lessons'][0]['blocks']);

        $this->upload($divergent)->assertStatus(422);

        $this->assertDatabaseMissing('courses', ['course_id' => 'MAT_ZLOMKY_EN']);
        $this->assertEmpty(Storage::disk('s3')->files('courses/MAT_ZLOMKY_EN'));
    }

    public function test_editing_one_variant_out_of_sync_with_its_sibling_is_refused(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $this->upload($this->english())->assertStatus(201);

        // The editor saves a Czech-only change to the answer logic.
        $edited = $this->czech(['version' => 2]);
        $edited['blocks'][0]['steps'][0]['question']['options'][0]['is_correct'] = false;
        $edited['blocks'][0]['steps'][0]['question']['options'][1]['is_correct'] = true;

        $response = $this->upload($edited);

        $response->assertStatus(422);
        $response->assertJsonPath('structure_conflict.against.course_id', 'MAT_ZLOMKY_EN');

        // The stored course is untouched: still v1, still the original logic.
        $this->assertSame(1, Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail()->version);
    }

    public function test_a_single_language_course_is_never_blocked(): void
    {
        $this->upload($this->czech())->assertStatus(201);

        $restructured = $this->czech(['version' => 2]);
        $restructured['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Tři', 'is_correct' => false,
        ];

        $this->upload($restructured)->assertStatus(200);
    }

    public function test_a_sibling_without_a_fingerprint_does_not_block(): void
    {
        // A legacy row from before this feature: grouped, but never fingerprinted.
        Course::create([
            'course_id' => 'MAT_ZLOMKY_LEGACY',
            'translation_key' => 'MAT_ZLOMKY',
            'name' => 'Legacy',
            'version' => 1,
            'language' => 'sk',
            'status' => 'draft',
            'structure_hash' => null,
        ]);

        $this->upload($this->czech())->assertStatus(201);
    }

    public function test_translating_a_course_keeps_its_own_pin_and_version(): void
    {
        $this->upload($this->czech(['pin' => 'AB12CD']))->assertStatus(201);
        $this->upload($this->english(['pin' => 'EF34GH', 'version' => 7]))->assertStatus(201);

        $this->assertSame('AB12CD', Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail()->pin);
        $this->assertSame('EF34GH', Course::where('course_id', 'MAT_ZLOMKY_EN')->firstOrFail()->pin);
        $this->assertSame(7, Course::where('course_id', 'MAT_ZLOMKY_EN')->firstOrFail()->version);
    }

    public function test_a_course_lists_its_sibling_translations(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $this->upload($this->english())->assertStatus(201);

        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $response = $this->actingAsAdmin()->getJson("/api/courses/{$czech->id}");

        $response->assertStatus(200);
        $translations = $response->json('data.translations');

        $this->assertCount(1, $translations);
        $this->assertSame('en', $translations[0]['language']);
        $this->assertSame('MAT_ZLOMKY_EN', $translations[0]['course_id']);
        $this->assertTrue($translations[0]['in_sync']);
    }

    public function test_the_sibling_listing_flags_a_variant_that_drifted(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $this->upload($this->english())->assertStatus(201);

        // Simulate drift that predates the check (e.g. a hand-patched row).
        Course::where('course_id', 'MAT_ZLOMKY_EN')->update(['structure_hash' => 'deadbeef']);

        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();
        $response = $this->actingAsAdmin()->getJson("/api/courses/{$czech->id}");

        $this->assertFalse($response->json('data.translations.0.in_sync'));
    }

    public function test_creating_a_course_through_store_records_its_group_and_structure(): void
    {
        $payload = $this->czech();

        $this->actingAsAdmin()->postJson('/api/courses', [
            'name' => $payload['name'],
            'course_id' => $payload['course_id'],
            'version' => 1,
            'language' => 'cs',
            'data' => $payload,
        ])->assertStatus(201);

        $course = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $this->assertSame('MAT_ZLOMKY', $course->translation_key);
        $this->assertNotNull($course->structure_hash);
    }

    public function test_store_rejects_an_unknown_language(): void
    {
        $this->actingAsAdmin()->postJson('/api/courses', [
            'name' => 'Kurz',
            'course_id' => 'MAT_X',
            'version' => 1,
            'language' => 'klingon',
            'data' => $this->czech(),
        ])->assertStatus(422)->assertJsonValidationErrors('language');
    }

    public function test_editing_content_through_update_is_also_checked(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $this->upload($this->english())->assertStatus(201);

        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $divergent = $this->czech();
        $divergent['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Tři', 'is_correct' => false,
        ];

        $response = $this->actingAsAdmin()
            ->putJson("/api/courses/{$czech->id}", ['data' => $divergent]);

        $response->assertStatus(422);
        $response->assertJsonPath('structure_conflict.against.course_id', 'MAT_ZLOMKY_EN');

        $this->assertSame(
            $czech->structure_hash,
            Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail()->structure_hash,
        );
    }

    public function test_editing_content_through_update_records_the_new_structure(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $restructured = $this->czech();
        $restructured['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Tři', 'is_correct' => false,
        ];

        $this->actingAsAdmin()
            ->putJson("/api/courses/{$czech->id}", ['data' => $restructured])
            ->assertStatus(200);

        $this->assertNotSame(
            $czech->structure_hash,
            Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail()->structure_hash,
        );
    }

    public function test_updating_only_metadata_is_never_blocked(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $this->upload($this->english())->assertStatus(201);

        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $this->actingAsAdmin()
            ->putJson("/api/courses/{$czech->id}", ['status' => 'published'])
            ->assertStatus(200);

        $this->assertSame(
            'published',
            Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail()->status,
        );
    }

    public function test_update_rejects_an_unknown_language(): void
    {
        $this->upload($this->czech())->assertStatus(201);
        $czech = Course::where('course_id', 'MAT_ZLOMKY')->firstOrFail();

        $this->actingAsAdmin()
            ->putJson("/api/courses/{$czech->id}", ['language' => 'klingon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language');
    }

    public function test_the_course_list_can_be_filtered_to_one_language(): void
    {
        $this->upload($this->czech(['status' => 'published']))->assertStatus(201);
        $this->upload($this->english(['status' => 'published']))->assertStatus(201);

        $codes = array_column(
            $this->getJson('/api/courses?language=en')->json('data'),
            'course_id',
        );

        $this->assertSame(['MAT_ZLOMKY_EN'], $codes);
    }
}
