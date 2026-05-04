<?php

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;

class BackfillCourseFlags extends Command
{
    protected $signature = 'courses:backfill-flags';
    protected $description = 'Backfill starts_with_quiz, only_once, logged_only, quiz_evaluate, and pin from stored course JSON data';

    public function handle(): int
    {
        $courses = Course::whereNotNull('data')->get();

        $updated = 0;
        foreach ($courses as $course) {
            $data = $course->data;
            if (!is_array($data)) {
                continue;
            }

            $changed = false;

            if (isset($data['pin']) && !$course->pin) {
                $course->pin = (int) $data['pin'];
                $changed = true;
            }

            if (isset($data['starts_with_quiz'])) {
                $course->starts_with_quiz = (bool) $data['starts_with_quiz'];
                $changed = true;
            }

            if (isset($data['only_once'])) {
                $course->only_once = (bool) $data['only_once'];
                $changed = true;
            }

            if (isset($data['logged_only'])) {
                $course->logged_only = (bool) $data['logged_only'];
                $changed = true;
            }

            if (isset($data['quiz_evaluate'])) {
                $course->quiz_evaluate = (bool) $data['quiz_evaluate'];
                $changed = true;
            }

            if ($changed) {
                $course->saveQuietly();
                $updated++;
                $this->line("Updated: {$course->course_id}");
            }
        }

        $this->info("Backfilled {$updated} of {$courses->count()} courses.");

        return self::SUCCESS;
    }
}
