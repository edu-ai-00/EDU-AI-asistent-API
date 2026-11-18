<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;

/**
 * Shared timing/duration formatting for the admin TSV exports
 * (ELO interactions + per-student answers). Keeps the "Doba" column
 * identical across both files.
 */
trait FormatsExportTiming
{
    /**
     * A block the student finished more than this long after their previous
     * block ended starts a new sitting — the same 30 min session gap the
     * ELO timestamp backfill uses.
     */
    private const PREVIOUS_BLOCK_MAX_GAP_MS = 30 * 60 * 1000;

    /**
     * Open a block that has no time of its own where the student's previous
     * block ended (BR-6NK9AX), and derive its duration from that.
     *
     * "No time of its own" means it was confirmed under a second after it
     * opened — the export would show Otevřeno = Potvrzeno and 0 s. Quiz
     * answers logged before BR-PTSQDD all look like this: they carry the
     * next question's open time. duration_ms decides when it is there: the
     * stored timestamps are whole seconds, so a span of a few ms can still
     * cross a second boundary.
     *
     * The previous block is the student's latest block (from any of $rows)
     * that ended before this one, at most PREVIOUS_BLOCK_MAX_GAP_MS earlier.
     * Without one — the first block, or the first after a break — the row
     * keeps its own values.
     *
     * @param  array<int, array{user_id: int, timing: array{opened: ?Carbon, confirmed: ?Carbon, duration_ms: ?int}}>  $rows
     * @return array<int, array{user_id: int, timing: array{opened: ?Carbon, confirmed: ?Carbon, duration_ms: ?int}}>
     */
    protected function openFromPreviousBlock(array $rows): array
    {
        // Row indexes per student, in the order their blocks ended.
        $byUser = [];
        foreach ($rows as $index => $row) {
            $confirmed = $row['timing']['confirmed'] ?? null;
            if ($confirmed !== null) {
                $byUser[$row['user_id']][$index] = $confirmed->getPreciseTimestamp(3);
            }
        }

        foreach ($byUser as $endsAt) {
            asort($endsAt);

            $latestEnd = null;   // [ms, Carbon] of the latest block seen
            $previousEnd = null; // the latest one strictly before the current block
            foreach ($endsAt as $index => $endMs) {
                if ($latestEnd !== null && $latestEnd[0] < $endMs) {
                    $previousEnd = $latestEnd;
                }
                $timing = $rows[$index]['timing'];
                $latestEnd = [$endMs, $timing['confirmed']];

                if (
                    $previousEnd === null
                    || ! $this->lacksOwnDuration($timing)
                    || $endMs - $previousEnd[0] > self::PREVIOUS_BLOCK_MAX_GAP_MS
                ) {
                    continue;
                }

                $rows[$index]['timing'] = [
                    'opened' => $previousEnd[1],
                    'confirmed' => $timing['confirmed'],
                    'duration_ms' => (int) round($endMs - $previousEnd[0]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether a block was confirmed under a second after it opened.
     *
     * @param  array{opened: ?Carbon, confirmed: ?Carbon, duration_ms: ?int}  $timing
     */
    private function lacksOwnDuration(array $timing): bool
    {
        if ($timing['opened'] === null || $timing['confirmed'] === null) {
            return false;
        }

        $spanMs = $timing['duration_ms']
            ?? abs($timing['confirmed']->getPreciseTimestamp(3) - $timing['opened']->getPreciseTimestamp(3));

        return $spanMs < 1000;
    }

    /**
     * Format the "Doba" cell from a pre-computed duration_ms (preferred)
     * or by diffing opened/confirmed timestamps.
     */
    protected function formatDurationCell(?int $durationMs, ?Carbon $openedAt, ?Carbon $confirmedAt): string
    {
        if ($durationMs !== null && $durationMs >= 0) {
            $seconds = (int) round($durationMs / 1000);

            return $seconds.'s';
        }

        if ($openedAt && $confirmedAt) {
            // Carbon 3 returns signed diffs. abs() guarantees a positive
            // value even if the caller passes the timestamps in either
            // order (which is how the export drifted into "-93s" cells
            // before duration_ms was introduced).
            $diff = (int) abs($confirmedAt->diffInSeconds($openedAt));

            return $diff.'s';
        }

        return '';
    }

    /**
     * Render the four timing cells [Den, Otevřeno, Potvrzeno, Doba] from a
     * resolved timing entry: ['opened' => ?Carbon, 'confirmed' => ?Carbon,
     * 'duration_ms' => ?int]. Empty strings when data is missing.
     */
    protected function timingCells(?array $timing): array
    {
        $openedAt = $timing['opened'] ?? null;
        $confirmedAt = $timing['confirmed'] ?? null;
        $dayAnchor = $confirmedAt ?? $openedAt;

        return [
            $dayAnchor ? $dayAnchor->format('j.n.Y') : '',
            $openedAt ? $openedAt->format('H:i:s') : '',
            $confirmedAt ? $confirmedAt->format('H:i:s') : '',
            $this->formatDurationCell($timing['duration_ms'] ?? null, $openedAt, $confirmedAt),
        ];
    }
}
