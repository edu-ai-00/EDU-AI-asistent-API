<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the hint / help / assistant columns appended to both admin exports
 * (BR-SPYYKR): the client already tracked every open, but neither TSV
 * reported it, so a fast answer that followed a help open looked unaided.
 */
class EloExportHintHelpTest extends TestCase
{
    use RefreshDatabase;

    private const ELO_EXPORT = '/api/admin/elo/interactions/course/course-hh/export';

    private const ANSWERS_EXPORT = '/api/admin/progress/course-hh/answers/export';

    /** "Blok" column index in each export. */
    private const ELO_BLOCK_COL = 2;

    private const ANSWERS_BLOCK_COL = 5;

    private const ASSISTANCE_HEADERS = ['Hint otevřen', 'Help otevřen', 'Asistent otevřen'];

    private function seedCourse(): Course
    {
        return Course::create([
            'course_id' => 'course-hh',
            'name' => 'Course HH',
            'status' => 'published',
            'data' => [
                'blocks' => [
                    [
                        'block_id' => 'blk_hint',
                        'steps' => [[
                            'id' => 's1',
                            'type' => 'question',
                            'question' => [
                                'type' => 'multiple_choice',
                                'options' => [['id' => 'a', 'text' => 'A', 'is_correct' => true]],
                            ],
                        ]],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Parse a streamed TSV export into field arrays. Parsing beats substring
     * matching here: fputcsv quotes any field containing a space, which every
     * new header and every multi-open cell does.
     *
     * @return list<list<string>>
     */
    private function exportRows(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        $content = ltrim($response->streamedContent(), "\xEF\xBB\xBF");
        $lines = array_filter(
            explode("\n", rtrim($content, "\r\n")),
            fn (string $l) => trim($l) !== ''
        );

        return array_values(array_map(
            fn (string $line) => array_map('strval', str_getcsv(rtrim($line, "\r"), "\t", '"', '\\')),
            $lines
        ));
    }

    /**
     * The three assistance headers, read from where the header row puts them.
     * Located by name rather than as "the last three": the ELO export
     * appends "Verze ELO" after them (BR-EBNMBF).
     *
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function assistanceHeaders(array $rows): array
    {
        $start = array_search(self::ASSISTANCE_HEADERS[0], $rows[0], true);
        $this->assertNotFalse($start, 'no assistance columns in the export header');

        return array_values(array_slice($rows[0], $start, 3));
    }

    /**
     * The three assistance cells of the row for [$blockId], in column order.
     *
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function assistanceCells(array $rows, string $blockId, int $blockColumn): array
    {
        $row = collect($rows)->skip(1)->first(
            fn (array $r) => ($r[$blockColumn] ?? null) === $blockId
        );
        $this->assertNotNull($row, "no export row for block {$blockId}");

        $start = array_search(self::ASSISTANCE_HEADERS[0], $rows[0], true);

        return array_values(array_slice($row, $start, 3));
    }

    public function test_elo_export_reports_every_hint_help_and_assistant_open(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_hint' => ['opened_at' => '2026-05-19T06:48:00Z', 'confirmed_at' => '2026-05-19T06:49:00Z'],
                    ],
                    'hint_help_usage' => [
                        'blk_hint' => [
                            // Three opens, deliberately out of order — the cell
                            // must come out chronological.
                            'hint_count' => 3,
                            'hint_events' => [
                                '2026-05-19T06:48:40Z',
                                '2026-05-19T06:48:12Z',
                                '2026-05-19T06:48:55Z',
                            ],
                            'help_count' => 1,
                            'help_events' => ['2026-05-19T06:48:50Z'],
                            'assistant_count' => 1,
                            'assistant_events' => ['2026-05-19T06:48:58Z'],
                        ],
                    ],
                ]],
            ],
        ]);

        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_hint',
            'course_id' => 'course-hh',
            'source' => 'lesson',
            'score' => 0.5,
            'opened_at' => '2026-05-19T06:48:00Z',
            'confirmed_at' => '2026-05-19T06:49:00Z',
            'duration_ms' => 60000,
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::ELO_EXPORT);

        $this->assertSame(self::ASSISTANCE_HEADERS, $this->assistanceHeaders($rows));

        $this->assertSame(
            ['06:48:12, 06:48:40, 06:48:55', '06:48:50', '06:48:58'],
            $this->assistanceCells($rows, 'blk_hint', self::ELO_BLOCK_COL)
        );
    }

    public function test_elo_export_falls_back_to_first_ts_for_legacy_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        // Data synced before per-occurrence tracking existed: counts and
        // first/last only, no *_events arrays. Also a traversal-only row —
        // there is no ELO interaction, so it is emitted by pass 2.
        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_hint' => ['opened_at' => '2026-05-19T06:48:00Z', 'confirmed_at' => '2026-05-19T06:49:00Z'],
                    ],
                    'hint_help_usage' => [
                        'blk_hint' => [
                            'hint_count' => 2,
                            'hint_first_ts' => '2026-05-19T06:48:12Z',
                            'hint_last_ts' => '2026-05-19T06:48:40Z',
                        ],
                    ],
                ]],
            ],
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::ELO_EXPORT);

        $this->assertSame(
            ['06:48:12', '', ''],
            $this->assistanceCells($rows, 'blk_hint', self::ELO_BLOCK_COL)
        );
    }

    public function test_elo_export_merges_quiz_root_usage_without_duplicating_lesson_opens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                // Quiz page writes hint_help_usage at the root, not under lessons.
                'hint_help_usage' => [
                    'blk_quiz' => [
                        'hint_count' => 1,
                        'hint_events' => ['2026-05-19T07:00:05Z'],
                    ],
                ],
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_hint' => ['opened_at' => '2026-05-19T06:48:00Z', 'confirmed_at' => '2026-05-19T06:49:00Z'],
                    ],
                    'hint_help_usage' => [
                        'blk_hint' => ['hint_count' => 1, 'hint_events' => ['2026-05-19T06:48:12Z']],
                    ],
                ]],
            ],
        ]);

        // The app mirrors lesson tracking into user_progress as well — the same
        // open must not be counted twice.
        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => 'course-hh',
            'lesson_id' => 'lesson-1',
            'progress_data' => [
                'hint_help_usage' => [
                    'blk_hint' => ['hint_count' => 1, 'hint_events' => ['2026-05-19T06:48:12Z']],
                ],
            ],
        ]);

        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_quiz',
            'course_id' => 'course-hh',
            'source' => 'quiz',
            'score' => 1.0,
            'confirmed_at' => '2026-05-19T07:00:30Z',
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::ELO_EXPORT);

        // Deduped: one time, not "06:48:12, 06:48:12".
        $this->assertSame(
            ['06:48:12', '', ''],
            $this->assistanceCells($rows, 'blk_hint', self::ELO_BLOCK_COL)
        );
        $this->assertSame(
            ['07:00:05', '', ''],
            $this->assistanceCells($rows, 'blk_quiz', self::ELO_BLOCK_COL)
        );
    }

    public function test_answers_export_carries_the_same_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => 'course-hh',
            'lesson_id' => 'lesson-1',
            'progress_data' => [
                'step_progress' => [
                    'blk_hint' => ['stepAnswers' => ['s1' => ['selectedOptionId' => 'a', 'isCorrect' => true]]],
                ],
                'hint_help_usage' => [
                    'blk_hint' => ['help_count' => 1, 'help_events' => ['2026-05-19T06:48:50Z']],
                ],
            ],
        ]);

        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_hint' => ['opened_at' => '2026-05-19T06:48:00Z', 'confirmed_at' => '2026-05-19T06:49:00Z'],
                    ],
                ]],
            ],
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::ANSWERS_EXPORT);

        $this->assertSame(self::ASSISTANCE_HEADERS, $this->assistanceHeaders($rows));

        // No hint, one help, no assistant.
        $this->assertSame(
            ['', '06:48:50', ''],
            $this->assistanceCells($rows, 'blk_hint', self::ANSWERS_BLOCK_COL)
        );
    }

    public function test_blocks_without_assistance_get_empty_cells(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_hint' => ['opened_at' => '2026-05-19T06:48:00Z', 'confirmed_at' => '2026-05-19T06:49:00Z'],
                    ],
                ]],
            ],
        ]);

        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_hint',
            'course_id' => 'course-hh',
            'source' => 'lesson',
            'score' => 1.0,
            'opened_at' => '2026-05-19T06:48:00Z',
            'confirmed_at' => '2026-05-19T06:49:00Z',
            'duration_ms' => 60000,
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->exportRows(self::ELO_EXPORT);

        $this->assertSame(
            ['', '', ''],
            $this->assistanceCells($rows, 'blk_hint', self::ELO_BLOCK_COL)
        );
    }
}
