<?php

namespace Tests\Feature;

use App\Models\DebugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-8FDF37 I3: debug_reports.user_id was declared nullOnDelete() but never
 * actually constrained() (migration 2026_02_27_300000), so Fluent silently
 * dropped the FK and nothing ever enforced it. Migration 2026_08_13_000007
 * adds the real constraint. This test exercises the stated reason that
 * migration exists — a general (non-minor) user deletion, which does not go
 * through StudentDataEraser's explicit delete, must anonymise the debug
 * report by nulling user_id rather than leaving it pointing at a row that no
 * longer exists.
 */
class DebugReportForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_user_nulls_their_debug_reports_user_id(): void
    {
        $user = User::factory()->create(['role' => 'student', 'is_guest' => false]);

        $report = DebugReport::create([
            'user_id' => $user->id,
            'app_version' => '1.0',
            'platform' => 'web',
            'report_data' => ['note' => 'test'],
        ]);

        $user->delete();

        $this->assertDatabaseHas('debug_reports', [
            'id' => $report->id,
            'user_id' => null,
        ]);
    }
}
