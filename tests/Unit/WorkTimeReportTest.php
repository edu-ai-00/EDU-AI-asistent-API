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

    public function test_sessions_carry_the_devices_their_heartbeats_came_from(): void
    {
        $base = 1783584000;
        $phone = ['platform' => 'android', 'type' => 'mobile'];
        $pc = ['platform' => 'web', 'type' => 'desktop'];

        $out = $this->report()->aggregate(
            // Two sessions: 10:00–10:02 and, after a 2 h break, 12:00–12:01.
            ['math' => [$base, $base + 60, $base + 120, $base + 7200, $base + 7260]],
            'Europe/Prague',
            ['math' => [
                ['at' => $base, ...$phone],
                ['at' => $base + 60, ...$phone],
                // Switched to the PC within the same session.
                ['at' => $base + 120, ...$pc],
                ['at' => $base + 7200, ...$pc],
                ['at' => $base + 7260, ...$pc],
            ]],
        );

        // Most recent first.
        $this->assertSame([$pc], $out['sessions'][0]['devices']);
        $this->assertSame([$phone, $pc], $out['sessions'][1]['devices']);
    }

    public function test_sessions_without_device_data_have_no_devices(): void
    {
        $base = 1783584000;

        // Older app builds sent no device, and ELO interactions never carry one.
        $out = $this->report()->aggregate(['math' => [$base, $base + 60]], 'Europe/Prague');

        $this->assertSame([], $out['sessions'][0]['devices']);
    }

    public function test_devices_are_matched_to_sessions_per_course(): void
    {
        $base = 1783584000;

        $out = $this->report()->aggregate(
            ['math' => [$base], 'lang' => [$base + 30]],
            'Europe/Prague',
            ['lang' => [['at' => $base + 30, 'platform' => 'ios', 'type' => 'tablet']]],
        );

        $byCourse = array_column($out['sessions'], 'devices', 'course_id');
        $this->assertSame([], $byCourse['math']);
        $this->assertSame([['platform' => 'ios', 'type' => 'tablet']], $byCourse['lang']);
    }
}
