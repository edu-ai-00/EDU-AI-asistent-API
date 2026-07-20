<?php

namespace Tests\Unit;

use App\Support\WorkSessionizer;
use App\Support\WorkTimeReport;
use PHPUnit\Framework\TestCase;

class WorkTimeReportTest extends TestCase
{
    private function report(): WorkTimeReport
    {
        return new WorkTimeReport(new WorkSessionizer(sessionGapSeconds: 1800, activeCapSeconds: 90));
    }

    public function test_empty_signals_produce_zero_totals(): void
    {
        $out = $this->report()->aggregate([], 'Europe/Prague');

        $this->assertSame(0, $out['total_seconds']);
        $this->assertSame([], $out['by_course']);
        $this->assertSame([], $out['by_day']);
        $this->assertSame([], $out['sessions']);
    }

    public function test_totals_sum_across_courses(): void
    {
        // 2026-07-09 10:00:00 Europe/Prague = 08:00:00 UTC = 1783584000.
        $base = 1783584000;
        $signals = [
            'math' => [$base, $base + 60, $base + 120],   // 90 lead + 60 + 60 = 210
            'lang' => [$base + 5],                          // 90
        ];

        $out = $this->report()->aggregate($signals, 'Europe/Prague');

        $this->assertSame(300, $out['total_seconds']);
        $this->assertSame(210, $out['by_course']['math']);
        $this->assertSame(90, $out['by_course']['lang']);
    }

    public function test_day_buckets_use_the_given_timezone(): void
    {
        // 1783584000 = 2026-07-09 08:00 UTC = 10:00 Europe/Prague → day 2026-07-09.
        $base = 1783584000;
        $out = $this->report()->aggregate(['math' => [$base, $base + 60]], 'Europe/Prague');

        $this->assertArrayHasKey('2026-07-09', $out['by_day']);
        $this->assertSame(150, $out['by_day']['2026-07-09']); // 90 + 60
    }

    public function test_sessions_are_reported_with_course_and_iso_bounds(): void
    {
        $base = 1783584000;
        $out = $this->report()->aggregate(['math' => [$base, $base + 60]], 'Europe/Prague');

        $this->assertCount(1, $out['sessions']);
        $this->assertSame('math', $out['sessions'][0]['course_id']);
        $this->assertSame(150, $out['sessions'][0]['duration']);
        $this->assertStringContainsString('2026-07-09', $out['sessions'][0]['start']);
    }
}
