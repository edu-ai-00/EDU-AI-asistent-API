<?php

namespace App\Support;

use App\Models\EloInteraction;
use App\Models\WorkHeartbeat;

/**
 * Gathers a user's activity signals (heartbeats + answer/quiz interactions)
 * per course and turns them into a work-time report (BR-9SAH2R).
 */
class WorkTimeService
{
    public function __construct(private readonly WorkTimeReport $report)
    {
    }

    /**
     * Build the full work-time report for one user.
     *
     * @return array{total_seconds: int, by_course: array<string,int>, by_day: array<string,int>, sessions: array<int, array{course_id: string, start: string, end: string, duration: int}>}
     */
    public function forUser(int $userId): array
    {
        $signalsByCourse = [];

        WorkHeartbeat::query()
            ->where('user_id', $userId)
            ->orderBy('occurred_at')
            ->get(['course_id', 'occurred_at'])
            ->each(function ($hb) use (&$signalsByCourse) {
                $signalsByCourse[$hb->course_id][] = $hb->occurred_at->getTimestamp();
            });

        EloInteraction::query()
            ->where('user_id', $userId)
            ->get(['course_id', 'confirmed_at', 'opened_at', 'created_at'])
            ->each(function ($it) use (&$signalsByCourse) {
                $at = $it->confirmed_at ?? $it->opened_at ?? $it->created_at;
                if ($at !== null) {
                    $signalsByCourse[$it->course_id][] = $at->getTimestamp();
                }
            });

        return $this->report->aggregate(
            $signalsByCourse,
            (string) config('work.timezone', 'Europe/Prague'),
        );
    }
}
