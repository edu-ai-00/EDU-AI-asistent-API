<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\CourseStorageService;
use Illuminate\Console\Command;

class BackfillCourseFlags extends Command
{
    protected $signature = 'courses:backfill-flags';
    protected $description = 'Backfill display metadata (lesson_count, estimated_minutes, description, emoji, author) and flags (starts_with_quiz, only_once, logged_only, quiz_evaluate, pin) from stored course JSON data';

    public function handle(CourseStorageService $storageService): int
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

            // Backfill denormalized display metadata so listings show course
            // params for not-yet-downloaded courses (BR-8SPECV). Only fill
            // lesson_count/estimated_minutes when currently empty, and only set
            // description/emoji/author from data when non-null.
            $display = $storageService->extractDisplayMetadata($data);

            if (!$course->lesson_count && $display['lesson_count'] > 0) {
                $course->lesson_count = $display['lesson_count'];
                $changed = true;
            }
            if (!$course->estimated_minutes && $display['estimated_minutes'] > 0) {
                $course->estimated_minutes = $display['estimated_minutes'];
                $changed = true;
            }
            if (!$course->description && $display['description'] !== null) {
                $course->description = $display['description'];
                $changed = true;
            }
            if (!$course->emoji && $display['emoji'] !== null) {
                $course->emoji = $display['emoji'];
                $changed = true;
            }
            if (!$course->author && $display['author'] !== null) {
                $course->author = $display['author'];
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
