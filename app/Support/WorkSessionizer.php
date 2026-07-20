<?php

namespace App\Support;

/**
 * Groups a stream of activity signal timestamps (heartbeats and answer/quiz
 * interactions) into work sessions and credits each session an active-work
 * duration.
 *
 * A session is a maximal run of signals where consecutive signals are no more
 * than [sessionGapSeconds] apart. Duration credits the first signal a lead of
 * [activeCapSeconds] and each subsequent gap min(gap, activeCapSeconds), so
 * dense heartbeats count in full while long idle gaps between sparse signals
 * are bounded (a 20-minute think is not counted as 20 minutes of work).
 */
class WorkSessionizer
{
    public function __construct(
        private readonly int $sessionGapSeconds = 1800,
        private readonly int $activeCapSeconds = 90,
    ) {
    }

    /**
     * @param  int[]  $timestamps  epoch-second signal times, any order
     * @return array<int, array{start: int, end: int, duration: int}>
     */
    public function sessionize(array $timestamps): array
    {
        if (empty($timestamps)) {
            return [];
        }

        sort($timestamps);

        $sessions = [];
        $start = $prev = $timestamps[0];
        $duration = $this->activeCapSeconds; // lead credit for the first signal

        for ($i = 1; $i < count($timestamps); $i++) {
            $t = $timestamps[$i];
            $gap = $t - $prev;

            if ($gap > $this->sessionGapSeconds) {
                $sessions[] = ['start' => $start, 'end' => $prev, 'duration' => $duration];
                $start = $t;
                $duration = $this->activeCapSeconds;
            } else {
                $duration += min($gap, $this->activeCapSeconds);
            }

            $prev = $t;
        }

        $sessions[] = ['start' => $start, 'end' => $prev, 'duration' => $duration];

        return $sessions;
    }
}
