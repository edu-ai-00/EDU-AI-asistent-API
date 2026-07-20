<?php

namespace Tests\Unit;

use App\Support\WorkSessionizer;
use PHPUnit\Framework\TestCase;

class WorkSessionizerTest extends TestCase
{
    private function sessionizer(): WorkSessionizer
    {
        // sessionGap = 1800s (30 min), activeCap = 90s.
        return new WorkSessionizer(sessionGapSeconds: 1800, activeCapSeconds: 90);
    }

    public function test_empty_input_yields_no_sessions(): void
    {
        $this->assertSame([], $this->sessionizer()->sessionize([]));
    }

    public function test_single_signal_is_credited_the_active_cap(): void
    {
        $sessions = $this->sessionizer()->sessionize([1000]);

        $this->assertCount(1, $sessions);
        $this->assertSame(1000, $sessions[0]['start']);
        $this->assertSame(1000, $sessions[0]['end']);
        $this->assertSame(90, $sessions[0]['duration']);
    }

    public function test_dense_run_credits_each_gap_in_full(): void
    {
        // 60s gaps, all <= 90s cap → 3 gaps * 60 + 90 lead = 270.
        $sessions = $this->sessionizer()->sessionize([0, 60, 120, 180]);

        $this->assertCount(1, $sessions);
        $this->assertSame(0, $sessions[0]['start']);
        $this->assertSame(180, $sessions[0]['end']);
        $this->assertSame(270, $sessions[0]['duration']);
    }

    public function test_long_gap_within_session_is_capped(): void
    {
        // gap 300s > 90s cap but <= 1800s session gap → same session,
        // credited min(300,90)=90 plus 90 lead = 180.
        $sessions = $this->sessionizer()->sessionize([0, 300]);

        $this->assertCount(1, $sessions);
        $this->assertSame(0, $sessions[0]['start']);
        $this->assertSame(300, $sessions[0]['end']);
        $this->assertSame(180, $sessions[0]['duration']);
    }

    public function test_gap_beyond_session_gap_splits_into_two_sessions(): void
    {
        // gap 2000s > 1800s → two separate single-signal sessions.
        $sessions = $this->sessionizer()->sessionize([0, 2000]);

        $this->assertCount(2, $sessions);
        $this->assertSame(0, $sessions[0]['start']);
        $this->assertSame(0, $sessions[0]['end']);
        $this->assertSame(90, $sessions[0]['duration']);
        $this->assertSame(2000, $sessions[1]['start']);
        $this->assertSame(2000, $sessions[1]['end']);
        $this->assertSame(90, $sessions[1]['duration']);
    }

    public function test_unsorted_input_is_ordered_first(): void
    {
        $sessions = $this->sessionizer()->sessionize([120, 0, 60]);

        $this->assertCount(1, $sessions);
        $this->assertSame(0, $sessions[0]['start']);
        $this->assertSame(120, $sessions[0]['end']);
        $this->assertSame(210, $sessions[0]['duration']); // 90 lead + 60 + 60
    }

    public function test_duplicate_timestamps_do_not_add_duration(): void
    {
        // Two identical signals: gap 0 → 90 lead + min(0,90)=0 = 90.
        $sessions = $this->sessionizer()->sessionize([500, 500]);

        $this->assertCount(1, $sessions);
        $this->assertSame(90, $sessions[0]['duration']);
    }
}
