<?php

namespace App\Http\Controllers\Concerns;

use App\Models\UserCourse;
use App\Models\UserProgress;
use Carbon\Carbon;

/**
 * Shared hint / help / assistant open tracking for the admin TSV exports
 * (ELO interactions + per-student answers), so both files report assistance
 * the same way.
 *
 * The client records one timestamp per open (BR-BBK5FP) and persists the map
 * to three different places depending on where the block was solved:
 *
 *   user_courses.progress_data.lessons[].hint_help_usage  — lesson blocks
 *   user_courses.progress_data.hint_help_usage            — quiz blocks (root)
 *   user_progress.progress_data.hint_help_usage           — lesson blocks again
 *
 * The last two overlap for lesson blocks (the app writes both), so timestamps
 * are unioned into a set keyed by the raw ISO string — identical opens collapse
 * instead of being reported twice.
 */
trait LoadsHintHelpUsage
{
    /**
     * Tracked assistance kinds, in export column order.
     *
     * @var list<string>
     */
    protected const HINT_HELP_KINDS = ['hint', 'help', 'assistant'];

    /**
     * Header cells matching {@see hintHelpCells()}.
     *
     * @return list<string>
     */
    protected function hintHelpHeaders(): array
    {
        return ['Hint otevřen', 'Help otevřen', 'Asistent otevřen'];
    }

    /**
     * Build "{user_id}:{block_id}" => [kind => [iso timestamp => true]].
     *
     * @param  string  $courseId  the public course slug (user_progress.course_id)
     * @param  int|null  $courseIntId  the courses PK (user_courses.course_id)
     * @param  array<int>  $userIds
     * @return array<string, array<string, array<string, true>>>
     */
    protected function buildHintHelpMap(string $courseId, ?int $courseIntId, array $userIds): array
    {
        $map = [];
        if (empty($userIds)) {
            return $map;
        }

        if ($courseIntId !== null) {
            $userCourses = UserCourse::where('course_id', $courseIntId)
                ->whereIn('user_id', $userIds)
                ->get();

            foreach ($userCourses as $uc) {
                $progressData = $uc->progress_data;
                if (! is_array($progressData)) {
                    continue;
                }

                // Root-level map — written by the quiz page.
                $this->absorbHintHelpUsage($map, $uc->user_id, $progressData['hint_help_usage'] ?? null);

                foreach (($progressData['lessons'] ?? []) as $lessonData) {
                    if (is_array($lessonData)) {
                        $this->absorbHintHelpUsage($map, $uc->user_id, $lessonData['hint_help_usage'] ?? null);
                    }
                }
            }
        }

        $records = UserProgress::where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'progress_data']);

        foreach ($records as $p) {
            $progressData = $p->progress_data;
            if (is_array($progressData)) {
                $this->absorbHintHelpUsage($map, $p->user_id, $progressData['hint_help_usage'] ?? null);
            }
        }

        return $map;
    }

    /**
     * Render one cell per tracked kind for a single map entry.
     *
     * @param  array<string, array<string, true>>|null  $entry
     * @return list<string>
     */
    protected function hintHelpCells(?array $entry): array
    {
        return array_map(
            fn (string $kind): string => $this->hintHelpCell($entry, $kind),
            self::HINT_HELP_KINDS
        );
    }

    /**
     * One chronological "H:i:s, H:i:s, …" cell — every open of [$kind], not
     * just the first, so a student who reopened the hint three times is
     * visible as three times. Empty string when the kind was never opened.
     *
     * Times are UTC, matching the Otevřeno / Potvrzeno columns.
     *
     * @param  array<string, array<string, true>>|null  $entry
     */
    protected function hintHelpCell(?array $entry, string $kind): string
    {
        $stamps = [];
        foreach (array_keys($entry[$kind] ?? []) as $iso) {
            try {
                $stamps[] = Carbon::parse((string) $iso);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($stamps === []) {
            return '';
        }

        usort($stamps, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return implode(', ', array_map(fn (Carbon $c) => $c->format('H:i:s'), $stamps));
    }

    /**
     * Fold one `hint_help_usage` map (blockId => usage) into [$map].
     *
     * @param  array<string, array<string, array<string, true>>>  $map
     */
    private function absorbHintHelpUsage(array &$map, int $userId, mixed $usage): void
    {
        if (! is_array($usage)) {
            return;
        }

        foreach ($usage as $blockId => $blockUsage) {
            if (! is_array($blockUsage)) {
                continue;
            }

            $key = $userId.':'.$blockId;
            foreach (self::HINT_HELP_KINDS as $kind) {
                foreach ($this->hintHelpTimestamps($blockUsage, $kind) as $iso) {
                    $map[$key][$kind][$iso] = true;
                }
            }
        }
    }

    /**
     * Per-occurrence timestamps for one kind. Prefers `{kind}_events[]` and
     * falls back to `{kind}_first_ts` for rows synced before per-occurrence
     * tracking existed — same precedence as the admin Postup tab.
     *
     * @param  array<string, mixed>  $usage
     * @return list<string>
     */
    private function hintHelpTimestamps(array $usage, string $kind): array
    {
        $events = $usage[$kind.'_events'] ?? null;
        if (is_array($events) && $events !== []) {
            return array_values(array_filter($events, 'is_string'));
        }

        $first = $usage[$kind.'_first_ts'] ?? null;

        return is_string($first) ? [$first] : [];
    }
}
