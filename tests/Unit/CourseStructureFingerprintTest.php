<?php

namespace Tests\Unit;

use App\Services\CourseStructureFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * BR-AYACEK — language variants of a course must share one structure.
 *
 * The fingerprint is the contract: strip every piece of translatable prose and
 * every per-variant identity field, then hash what is left. Two variants that
 * only differ in wording must produce the same hash; anything that changes the
 * teaching logic must produce a different one.
 */
class CourseStructureFingerprintTest extends TestCase
{
    private function czechCourse(array $overrides = []): array
    {
        return array_merge([
            'export_type' => 'course_v2',
            'course_id' => 'MAT_ZLOMKY',
            'version' => 3,
            'name' => 'Zlomky',
            'description' => 'Kurz o zlomcích',
            'language' => 'cs',
            'author' => 'Jan Novák',
            'updated' => '2026-08-01',
            'status' => 'published',
            'pin' => 'AB12CD',
            'emoji' => '🔢',
            'quiz_evaluate' => true,
            'lessons' => [[
                'lesson_id' => 'L1',
                'version' => 1,
                'name' => 'Úvod',
                'description' => 'První lekce',
                'order' => 1,
                'blocks' => [
                    ['block_id' => 'B1', 'order' => 1],
                    ['block_id' => 'B2', 'order' => 2],
                ],
            ]],
            'blocks' => [[
                'export_type' => 'block_v2',
                'block_id' => 'B1',
                'version' => 1,
                'language' => 'cs',
                'author' => 'Jan Novák',
                'updated' => '2026-08-01',
                'status' => 'published',
                'type' => 'question',
                'duration' => '2m',
                'xp' => 10,
                'default_practice' => true,
                'gpf' => ['domain' => 'Number', 'construct' => 'N2', 'grade' => 5, 'level' => 3],
                'learning' => ['concepts' => ['Zlomky'], 'bloom_level' => 2, 'difficulty' => 3],
                'steps' => [[
                    'id' => 's1',
                    'type' => 'question',
                    'order' => 1,
                    'content' => 'Kolik je 1/2 + 1/2?',
                    'hint' => 'Sečti čitatele',
                    'question' => [
                        'type' => 'multiple_choice',
                        'options' => [
                            ['id' => 'o1', 'text' => 'Jedna', 'is_correct' => true, 'feedback' => 'Správně!'],
                            ['id' => 'o2', 'text' => 'Dvě', 'is_correct' => false, 'feedback' => 'Zkus znovu'],
                        ],
                        'solution' => 'Jedna polovina plus jedna polovina je jedna.',
                    ],
                ]],
            ], [
                'export_type' => 'block_v2',
                'block_id' => 'B2',
                'type' => 'display',
                'duration' => '1m',
                'gpf' => ['domain' => 'Number', 'construct' => 'N2', 'grade' => 5, 'level' => 3],
                'learning' => ['concepts' => ['Zlomky'], 'bloom_level' => 1, 'difficulty' => 2],
                'steps' => [[
                    'id' => 's1',
                    'type' => 'text',
                    'order' => 1,
                    'content' => 'Zlomek je část celku.',
                ]],
            ]],
        ], $overrides);
    }

    /** The English variant: same logic, translated prose, own id/pin/version. */
    private function englishCourse(): array
    {
        $course = $this->czechCourse();

        $course['course_id'] = 'MAT_ZLOMKY_EN';
        $course['name'] = 'Fractions';
        $course['description'] = 'A course about fractions';
        $course['language'] = 'en';
        $course['author'] = 'John Newman';
        $course['updated'] = '2026-08-20';
        $course['status'] = 'draft';
        $course['pin'] = 'EF34GH';
        $course['version'] = 1;

        $course['lessons'][0]['name'] = 'Introduction';
        $course['lessons'][0]['description'] = 'First lesson';

        $course['blocks'][0]['language'] = 'en';
        $course['blocks'][0]['author'] = 'John Newman';
        $course['blocks'][0]['status'] = 'draft';
        $course['blocks'][0]['steps'][0]['content'] = 'What is 1/2 + 1/2?';
        $course['blocks'][0]['steps'][0]['hint'] = 'Add the numerators';
        $course['blocks'][0]['steps'][0]['question']['options'][0]['text'] = 'One';
        $course['blocks'][0]['steps'][0]['question']['options'][0]['feedback'] = 'Correct!';
        $course['blocks'][0]['steps'][0]['question']['options'][1]['text'] = 'Two';
        $course['blocks'][0]['steps'][0]['question']['options'][1]['feedback'] = 'Try again';
        $course['blocks'][0]['steps'][0]['question']['solution'] = 'A half plus a half is one.';

        $course['blocks'][1]['steps'][0]['content'] = 'A fraction is a part of a whole.';

        return $course;
    }

    public function test_a_pure_translation_keeps_the_same_fingerprint(): void
    {
        $this->assertSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($this->englishCourse()),
        );
    }

    public function test_fingerprint_is_independent_of_key_order(): void
    {
        $course = $this->czechCourse();
        $shuffled = array_reverse($course, true);
        $shuffled['blocks'][0] = array_reverse($shuffled['blocks'][0], true);

        $this->assertSame(
            CourseStructureFingerprint::compute($course),
            CourseStructureFingerprint::compute($shuffled),
        );
    }

    public function test_localised_media_urls_do_not_change_the_fingerprint(): void
    {
        $localised = $this->englishCourse();
        $localised['blocks'][1]['steps'][0]['image'] = ['url' => 'https://cdn/en/fraction.png', 'alt' => 'A fraction'];

        $czech = $this->czechCourse();
        $czech['blocks'][1]['steps'][0]['image'] = ['url' => 'https://cdn/cs/zlomek.png', 'alt' => 'Zlomek'];

        $this->assertSame(
            CourseStructureFingerprint::compute($czech),
            CourseStructureFingerprint::compute($localised),
        );
    }

    public function test_adding_an_answer_option_changes_the_fingerprint(): void
    {
        $extra = $this->englishCourse();
        $extra['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Three', 'is_correct' => false,
        ];

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($extra),
        );
    }

    public function test_flipping_the_correct_answer_changes_the_fingerprint(): void
    {
        $flipped = $this->englishCourse();
        $flipped['blocks'][0]['steps'][0]['question']['options'][0]['is_correct'] = false;
        $flipped['blocks'][0]['steps'][0]['question']['options'][1]['is_correct'] = true;

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($flipped),
        );
    }

    public function test_changing_branching_changes_the_fingerprint(): void
    {
        $branched = $this->englishCourse();
        $branched['blocks'][0]['steps'][0]['question']['options'][1]['go_to'] = 'AGAIN';

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($branched),
        );
    }

    public function test_dropping_a_block_from_a_lesson_changes_the_fingerprint(): void
    {
        $shorter = $this->englishCourse();
        array_pop($shorter['lessons'][0]['blocks']);
        array_pop($shorter['blocks']);

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($shorter),
        );
    }

    public function test_reordering_blocks_changes_the_fingerprint(): void
    {
        $reordered = $this->englishCourse();
        $reordered['lessons'][0]['blocks'][0]['order'] = 2;
        $reordered['lessons'][0]['blocks'][1]['order'] = 1;

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($reordered),
        );
    }

    public function test_changing_didactic_metadata_changes_the_fingerprint(): void
    {
        $retagged = $this->englishCourse();
        $retagged['blocks'][0]['learning']['difficulty'] = 5;

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($retagged),
        );
    }

    public function test_toggling_practice_membership_changes_the_fingerprint(): void
    {
        $toggled = $this->englishCourse();
        $toggled['blocks'][0]['default_practice'] = false;

        $this->assertNotSame(
            CourseStructureFingerprint::compute($this->czechCourse()),
            CourseStructureFingerprint::compute($toggled),
        );
    }

    public function test_diff_names_the_block_whose_options_differ(): void
    {
        $extra = $this->englishCourse();
        $extra['blocks'][0]['steps'][0]['question']['options'][] = [
            'id' => 'o3', 'text' => 'Three', 'is_correct' => false,
        ];

        $diff = CourseStructureFingerprint::diff($this->czechCourse(), $extra);

        $this->assertNotEmpty($diff);
        $this->assertStringContainsString('B1', implode("\n", $diff));
    }

    public function test_diff_is_empty_for_a_pure_translation(): void
    {
        $this->assertSame(
            [],
            CourseStructureFingerprint::diff($this->czechCourse(), $this->englishCourse()),
        );
    }

    public function test_diff_names_a_missing_block(): void
    {
        $shorter = $this->englishCourse();
        array_pop($shorter['lessons'][0]['blocks']);
        array_pop($shorter['blocks']);

        $diff = CourseStructureFingerprint::diff($this->czechCourse(), $shorter);

        $this->assertNotEmpty($diff);
        $this->assertStringContainsString('B2', implode("\n", $diff));
    }
}
