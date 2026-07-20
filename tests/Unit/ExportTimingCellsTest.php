<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UserProgressController;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the Den/Otevřeno/Potvrzeno/Doba cell formatting shared by the
 * ELO export and the per-student answers export (FormatsExportTiming).
 *
 * Pure logic — no DB.
 */
class ExportTimingCellsTest extends TestCase
{
    private function cells(?array $timing): array
    {
        $m = new ReflectionMethod(UserProgressController::class, 'timingCells');

        return $m->invokeArgs(new UserProgressController, [$timing]);
    }

    public function test_missing_timing_yields_empty_cells(): void
    {
        $this->assertSame(['', '', '', ''], $this->cells(null));
        $this->assertSame(['', '', '', ''], $this->cells([]));
    }

    public function test_opened_and_confirmed_are_diffed_for_doba(): void
    {
        // Matches the reporter's example: 06:48:42 → 06:49:10 = 28s.
        [$den, $otevreno, $potvrzeno, $doba] = $this->cells([
            'opened' => Carbon::parse('2026-05-19 06:48:42'),
            'confirmed' => Carbon::parse('2026-05-19 06:49:10'),
            'duration_ms' => null,
        ]);

        $this->assertSame('19.5.2026', $den);
        $this->assertSame('06:48:42', $otevreno);
        $this->assertSame('06:49:10', $potvrzeno);
        $this->assertSame('28s', $doba);
    }

    public function test_explicit_duration_ms_is_preferred_over_diff(): void
    {
        [, , , $doba] = $this->cells([
            'opened' => Carbon::parse('2026-05-19 06:48:42'),
            'confirmed' => Carbon::parse('2026-05-19 06:49:10'),
            'duration_ms' => 12_000, // 12s — overrides the 28s wall-clock diff
        ]);

        $this->assertSame('12s', $doba);
    }

    public function test_confirmed_only_anchors_the_day_without_opened_or_doba(): void
    {
        [$den, $otevreno, $potvrzeno, $doba] = $this->cells([
            'opened' => null,
            'confirmed' => Carbon::parse('2026-05-19 06:49:10'),
            'duration_ms' => null,
        ]);

        $this->assertSame('19.5.2026', $den);
        $this->assertSame('', $otevreno);
        $this->assertSame('06:49:10', $potvrzeno);
        $this->assertSame('', $doba);
    }
}
