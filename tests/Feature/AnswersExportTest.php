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
 * Covers GET /api/admin/progress/{courseId}/answers/export — the last
 * column set (previously "Aktualizováno") is now per-block timing
 * Den/Otevřeno/Potvrzeno/Doba, mirroring the ELO export (BR-EQ9RVQ).
 */
class AnswersExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedCourse(): Course
    {
        return Course::create([
            'course_id' => 'course-x',
            'name' => 'Course X',
            'status' => 'published',
            'data' => [
                'blocks' => [
                    [
                        'block_id' => 'blk_elo',
                        'steps' => [[
                            'id' => 's1',
                            'type' => 'question',
                            'question' => [
                                'type' => 'multiple_choice',
                                'options' => [['id' => 'a', 'text' => 'A', 'is_correct' => true]],
                            ],
                        ]],
                    ],
                    [
                        'block_id' => 'blk_ts',
                        'steps' => [[
                            'id' => 's1',
                            'type' => 'question',
                            'question' => ['type' => 'open'],
                        ]],
                    ],
                ],
            ],
        ]);
    }

    public function test_export_replaces_aktualizovano_with_per_block_timing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->seedCourse();

        UserProgress::create([
            'user_id' => $student->id,
            'course_id' => 'course-x',
            'lesson_id' => 'lesson-1',
            'progress_data' => [
                'step_progress' => [
                    'blk_elo' => ['stepAnswers' => ['s1' => ['selectedOptionId' => 'a', 'isCorrect' => true]]],
                    'blk_ts' => ['stepAnswers' => ['s1' => ['textAnswer' => 'ahoj']]],
                ],
            ],
        ]);

        // block_timestamps for BOTH blocks (fallback timing source).
        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id, // integer FK
            'progress_data' => [
                'lessons' => [[
                    'block_timestamps' => [
                        'blk_elo' => ['opened_at' => '2026-05-19 06:48:00', 'confirmed_at' => '2026-05-19 06:49:00'],
                        'blk_ts' => ['opened_at' => '2026-05-19 06:48:42', 'confirmed_at' => '2026-05-19 06:49:10'],
                    ],
                ]],
            ],
        ]);

        // ELO interaction wins for blk_elo: its duration_ms (5s) beats the
        // 60s block_timestamps wall-clock diff.
        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'blk_elo',
            'course_id' => 'course-x',
            'source' => 'lesson',
            'score' => 0.8,
            'opened_at' => '2026-05-19 06:48:00',
            'confirmed_at' => '2026-05-19 06:49:00',
            'duration_ms' => 5000,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->get('/api/admin/progress/course-x/answers/export');
        $response->assertOk();

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($content))));

        // Header: new timing columns present, old column gone.
        $header = $lines[0];
        $this->assertStringContainsString("Den\tOtevřeno\tPotvrzeno\tDoba", $header);
        $this->assertStringNotContainsString('Aktualizováno', $header);

        $rows = collect($lines)->filter(fn ($l) => str_contains($l, "\t"))->skip(1)->values();

        $eloRow = $rows->first(fn ($l) => str_contains($l, "\tblk_elo\t"));
        $tsRow = $rows->first(fn ($l) => str_contains($l, "\tblk_ts\t"));

        $this->assertNotNull($eloRow);
        $this->assertNotNull($tsRow);

        // blk_elo: duration_ms preferred → 5s; timing present.
        $this->assertStringContainsString("06:48:00\t06:49:00\t5s", $eloRow);

        // blk_ts: no ELO interaction → block_timestamps diff (28s).
        $this->assertStringContainsString("06:48:42\t06:49:10\t28s", $tsRow);
    }
}
