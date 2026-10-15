<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\TaskCalibrationService;
use Illuminate\Console\Command;

/**
 * Rebuild task difficulty D and its update counts from every answer, in
 * answer order (BR-697NCA). See TaskCalibrationService::recalibrateBlock.
 *
 *   php artisan elo:recalibrate                 # every course
 *   php artisan elo:recalibrate ZLOMKY-01       # one course
 *   php artisan elo:recalibrate --dry-run       # compute, write nothing
 *
 * Run once before switching ELO_TASK_CALIBRATION on. While it is on, the
 * scheduler runs it nightly: offline batches reach the server late, and
 * answers from app builds that predate profil_elo_before are only applied
 * here.
 */
class RecalibrateTaskDifficulty extends Command
{
    protected $signature = 'elo:recalibrate
                            {course_id? : Only this course (its course_id); every course when omitted}
                            {--dry-run : Compute and report, write nothing}';

    protected $description = 'Recalibrate task difficulty (ELO D) from every answer, in answer order';

    public function handle(TaskCalibrationService $calibration): int
    {
        $query = Course::query()->whereNotNull('data');
        if ($this->argument('course_id')) {
            $query->where('course_id', $this->argument('course_id'));
        }

        $dryRun = (bool) $this->option('dry-run');
        // Language variants share block ids: a block is replayed once per run.
        $done = [];
        $courses = 0;
        $totals = ['blocks' => 0, 'interactions' => 0, 'applied' => 0, 'changed' => 0];

        // In chunks: `data` is the full course JSON, too big to load all at once.
        foreach ($query->lazyById(20, 'id') as $course) {
            $before = count($done);
            $result = $calibration->recalibrateCourse($course, dryRun: $dryRun, done: $done);
            $courses++;

            $totals['blocks'] += count($done) - $before;
            $totals['interactions'] += $result['interactions'];
            $totals['applied'] += $result['applied'];
            $totals['changed'] += $result['changed'];

            $this->line(sprintf(
                '%s: %d block(s), %d answer(s), %d applied',
                $course->course_id,
                count($done) - $before,
                $result['interactions'],
                $result['applied'],
            ));
        }

        $this->info(sprintf(
            '%s %d course(s): %d block(s), %d answer(s), %d applied, %d row(s) %s.',
            $dryRun ? 'Dry run over' : 'Recalibrated',
            $courses,
            $totals['blocks'],
            $totals['interactions'],
            $totals['applied'],
            $totals['changed'],
            $dryRun ? 'would change' : 'changed',
        ));

        return self::SUCCESS;
    }
}
