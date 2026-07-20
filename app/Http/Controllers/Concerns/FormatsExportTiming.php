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
