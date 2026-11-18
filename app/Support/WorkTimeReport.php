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
     * @param  array<string, array<int, array{at: int, platform: ?string, type: ?string}>>  $devicesByCourse
     *         course_id => the signals that know which device they came from
     *         (heartbeats); each session lists the devices seen inside it
     * @return array{total_seconds: int, by_course: array<string,int>, by_day: array<string,int>, sessions: array<int, array{course_id: string, start: string, end: string, duration: int, devices: array<int, array{platform: ?string, type: ?string}>}>}
     */
    public function aggregate(array $signalsByCourse, string $timezone, array $devicesByCourse = []): array
    {
        $totalSeconds = 0;
        $byCourse = [];
        $byDay = [];
        $sessions = [];

        foreach ($signalsByCourse as $courseId => $timestamps) {
            $devices = $devicesByCourse[$courseId] ?? [];
            usort($devices, fn ($a, $b) => $a['at'] <=> $b['at']);
            $cursor = 0;

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
                    'devices' => $this->devicesWithin($devices, $cursor, $session['start'], $session['end']),
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

    /**
     * Distinct devices among the device signals inside [start, end], in the
     * order they were first used (BR-Y2TJ74). Sessions arrive in chronological
     * order, so [cursor] only moves forward and each signal is read once.
     *
     * @param  array<int, array{at: int, platform: ?string, type: ?string}>  $devices  sorted by `at`
     * @return array<int, array{platform: ?string, type: ?string}>
     */
    private function devicesWithin(array $devices, int &$cursor, int $start, int $end): array
    {
        $count = count($devices);
        while ($cursor < $count && $devices[$cursor]['at'] < $start) {
            $cursor++;
        }

        $found = [];
        while ($cursor < $count && $devices[$cursor]['at'] <= $end) {
            $device = ['platform' => $devices[$cursor]['platform'], 'type' => $devices[$cursor]['type']];
            $found[$device['platform'].'|'.$device['type']] ??= $device;
            $cursor++;
        }

        return array_values($found);
    }
}
