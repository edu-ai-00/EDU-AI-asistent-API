<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\GpfVectorCheck;
use Illuminate\Console\Command;

/**
 * One-off, read-only audit of the GPF vectors already stored in course
 * content (BR-S9SVNM).
 *
 * A block whose relation_vector holds anything but exactly 0, 1 or 2 (or
 * whose elo_vector leaves 0–10, or either vector is not 35 slots long) makes
 * the app's ELO engine throw; the app swallows that and the block silently
 * stops moving ELO. The admin now refuses to save such blocks, but content
 * saved before that still carries them. This lists every one so an author
 * can fix it in the course editor.
 *
 *   php artisan courses:check-gpf-vectors
 *   php artisan courses:check-gpf-vectors --course=ZLOMKY-01
 *
 * Writes nothing — the fix is an editorial decision, not something to
 * guess. Checks the current version of every (not deleted) course.
 */
class CheckCourseGpfVectors extends Command
{
    protected $signature = 'courses:check-gpf-vectors
                            {--course= : Only check the course with this course_id}';

    protected $description = 'List course blocks whose GPF (ELO) vectors the app would reject, without changing anything';

    public function handle(): int
    {
        $query = Course::query()->whereNotNull('data');
        if ($this->option('course')) {
            $query->where('course_id', $this->option('course'));
        }

        $checked = 0;
        $affectedCourses = 0;
        $affectedBlocks = 0;
        $issueCount = 0;

        // In chunks: `data` is the full course JSON, too big to load all at once.
        foreach ($query->lazyById(50, 'id') as $course) {
            $data = $course->data;
            if (! is_array($data)) {
                continue;
            }
            $checked++;

            $issues = GpfVectorCheck::course($data);
            if ($issues === []) {
                continue;
            }

            $affectedCourses++;
            $affectedBlocks += count(array_unique(array_column($issues, 'block_id')));
            $issueCount += count($issues);

            $this->newLine();
            $this->warn(sprintf(
                'Course #%d %s v%s — "%s" (%d problem(s)):',
                $course->id,
                $course->course_id,
                $course->version,
                $course->name,
                count($issues),
            ));

            foreach ($issues as $issue) {
                $lesson = $issue['lesson'] !== null ? " [{$issue['lesson']}]" : '';
                $this->line("  {$issue['block_id']}{$lesson}: {$issue['message']}");
            }
        }

        $this->newLine();

        if ($issueCount === 0) {
            $this->info("Checked {$checked} course(s): no invalid GPF vectors found.");

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Checked %d course(s): %d problem(s) in %d block(s) across %d course(s). Nothing was changed.',
            $checked,
            $issueCount,
            $affectedBlocks,
            $affectedCourses,
        ));

        return self::FAILURE;
    }
}
