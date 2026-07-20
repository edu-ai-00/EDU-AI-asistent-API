<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UserProgressController;
use App\Models\UserProgress;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the questionnaire answer-extraction logic that powers
 * GET /api/admin/progress/{courseId}/answers (+ /export).
 *
 * Pure logic — no DB. Exercises the real course_v2/block_v2 shape:
 * a graded MCQ block, an attitude/survey block (no is_correct),
 * a multi-select step, and an open-ended text step.
 */
class QuestionnaireAnswerExtractionTest extends TestCase
{
    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod(UserProgressController::class, $method);

        return $m->invokeArgs(new UserProgressController(), $args);
    }

    private function courseData(): array
    {
        return [
            'blocks' => [
                [
                    'block_id' => 'GPF-vstupni-test-v3_01',
                    'steps' => [
                        ['id' => 's1', 'type' => 'text', 'content' => 'Jak daleko leží -6 a -123?'],
                        ['id' => 's2', 'type' => 'question', 'question' => [
                            'type' => 'multiple_choice',
                            'options' => [
                                ['id' => 'opt_1', 'text' => '-129', 'is_correct' => false],
                                ['id' => 'opt_3', 'text' => '117', 'is_correct' => true],
                            ],
                        ]],
                    ],
                ],
                [
                    // Attitude/questionnaire block — no correct answer.
                    'block_id' => 'MAT_postoj_3',
                    'steps' => [
                        ['id' => 's1', 'type' => 'text', 'content' => 'Mám matematiku rád/a.'],
                        ['id' => 's2', 'type' => 'question', 'question' => [
                            'type' => 'multiple_choice',
                            'options' => [
                                ['id' => 'a', 'text' => 'Souhlasím'],
                                ['id' => 'b', 'text' => 'Nesouhlasím'],
                            ],
                        ]],
                    ],
                ],
                [
                    'block_id' => 'open_block',
                    'steps' => [
                        ['id' => 's1', 'type' => 'text', 'content' => 'Co bys zlepšil?'],
                        ['id' => 's2', 'type' => 'question', 'question' => ['type' => 'open']],
                    ],
                ],
            ],
        ];
    }

    public function test_answer_key_maps_options_and_correctness(): void
    {
        $key = $this->call('buildAnswerKeyForCourse', [$this->courseData()]);

        $this->assertSame('Jak daleko leží -6 a -123?', $key['GPF-vstupni-test-v3_01']['question']);
        $this->assertSame('117', $key['GPF-vstupni-test-v3_01']['steps']['s2']['options']['opt_3']);
        $this->assertSame(['opt_3'], $key['GPF-vstupni-test-v3_01']['steps']['s2']['correct']);
        // Survey block: no correct option.
        $this->assertSame([], $key['MAT_postoj_3']['steps']['s2']['correct']);
    }

    public function test_extract_rows_single_survey_and_open_answers(): void
    {
        $key = $this->call('buildAnswerKeyForCourse', [$this->courseData()]);

        $progress = (new UserProgress())->forceFill([
            'progress_data' => [
                'step_progress' => [
                    'GPF-vstupni-test-v3_01' => [
                        'stepAnswers' => [
                            's2' => ['selectedOptionId' => 'opt_3', 'isCorrect' => true],
                        ],
                    ],
                    'MAT_postoj_3' => [
                        'stepAnswers' => [
                            's2' => ['selectedOptionId' => 'b'], // no isCorrect — survey
                        ],
                    ],
                    'open_block' => [
                        'stepAnswers' => [
                            's2' => ['textAnswer' => 'Více příkladů.'],
                        ],
                    ],
                ],
            ],
        ]);

        $rows = $this->call('extractAnswerRows', [$progress, $key]);

        $this->assertCount(3, $rows);

        $byBlock = collect($rows)->keyBy('block_id');

        // Graded MCQ: concrete choice id + text + correctness resolved.
        $mcq = $byBlock['GPF-vstupni-test-v3_01'];
        $this->assertSame('opt_3', $mcq['selected_option_ids']);
        $this->assertSame('117', $mcq['selected_option_texts']);
        $this->assertTrue($mcq['is_correct']);

        // Survey: choice captured, is_correct null (not graded).
        $survey = $byBlock['MAT_postoj_3'];
        $this->assertSame('b', $survey['selected_option_ids']);
        $this->assertSame('Nesouhlasím', $survey['selected_option_texts']);
        $this->assertNull($survey['is_correct']);

        // Open text captured.
        $this->assertSame('Více příkladů.', $byBlock['open_block']['open_text']);
    }

    public function test_extract_rows_multi_select(): void
    {
        $key = $this->call('buildAnswerKeyForCourse', [$this->courseData()]);

        $progress = (new UserProgress())->forceFill([
            'progress_data' => [
                'step_progress' => [
                    'GPF-vstupni-test-v3_01' => [
                        'stepAnswers' => [
                            's2' => ['selectedOptionIds' => ['opt_1', 'opt_3']],
                        ],
                    ],
                ],
            ],
        ]);

        $rows = $this->call('extractAnswerRows', [$progress, $key]);

        $this->assertSame('opt_1, opt_3', $rows[0]['selected_option_ids']);
        $this->assertSame('-129 | 117', $rows[0]['selected_option_texts']);
        $this->assertNull($rows[0]['is_correct']);
    }
}
