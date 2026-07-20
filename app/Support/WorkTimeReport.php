<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns per-course activity signal streams into work-time aggregations
 * (total, per-course, per-day, and a session list) for the admin student
 * detail view (BR-9SAH2R).
 *
 * Day buckets attribute a whole session's duration to the calendar date of its
 * start in the requested timezone (v1 simplification for midnight-crossing).
 */
class WorkTimeReport
{
    public function __construct(private readonly WorkSessionizer $sessionizer)
    {
    }

    /**
     * @param  array<string, int[]>  $signalsByCourse  course_id => epoch-second signal times
     * @return array{total_seconds: int, by_course: array<string,int>, by_day: array<string,int>, sessions: array<int, array{course_id: string, start: string, end: string, duration: int}>}
     */
    public function aggregate(array $signalsByCourse, string $timezone): array
    {
        $totalSeconds = 0;
        $byCourse = [];
        $byDay = [];
        $sessions = [];

        foreach ($signalsByCourse as $courseId => $timestamps) {
            foreach ($this->sessionizer->sessionize($timestamps) as $session) {
                $duration = $session['duration'];
                $start = Carbon::createFromTimestamp($session['start'], $timezone);
                $end = Carbon::createFromTimestamp($session['end'], $timezone);
                $day = $start->format('Y-m-d');

                $totalSeconds += $duration;
                $byCourse[$courseId] = ($byCourse[$courseId] ?? 0) + $duration;
                $byDay[$day] = ($byDay[$day] ?? 0) + $duration;

                $sessions[] = [
                    'course_id' => (string) $courseId,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                    'duration' => $duration,
                ];
            }
        }

        // Deterministic order: most recent session first.
        usort($sessions, fn ($a, $b) => strcmp($b['start'], $a['start']));
        ksort($byDay);

        return [
            'total_seconds' => $totalSeconds,
            'by_course' => $byCourse,
            'by_day' => $byDay,
            'sessions' => $sessions,
        ];
    }
}
