<?php

namespace Tests\Feature;

use App\Models\EloInteraction;
use App\Models\User;
use App\Models\WorkHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_ingest_is_idempotent_on_client_uuid(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = ['heartbeats' => [
            ['client_uuid' => '11111111-1111-4111-8111-111111111111', 'course_id' => 'math', 'occurred_at' => '2026-07-09T10:00:00+02:00'],
            ['client_uuid' => '22222222-2222-4222-8222-222222222222', 'course_id' => 'math', 'occurred_at' => '2026-07-09T10:01:00+02:00'],
        ]];

        $this->postJson('/api/work/heartbeats', $payload)->assertOk();
        // Re-send the same batch — must not duplicate.
        $this->postJson('/api/work/heartbeats', $payload)->assertOk();

        $this->assertSame(2, WorkHeartbeat::where('user_id', $user->id)->count());
    }

    public function test_future_timestamps_are_clamped_to_now(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/work/heartbeats', ['heartbeats' => [
            ['client_uuid' => '33333333-3333-4333-8333-333333333333', 'course_id' => 'math', 'occurred_at' => '2099-01-01T00:00:00+00:00'],
        ]])->assertOk();

        $hb = WorkHeartbeat::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($hb->occurred_at->isBefore(now()->addMinutes(6)));
    }

    public function test_admin_work_time_report_combines_heartbeats_and_interactions(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        WorkHeartbeat::create([
            'user_id' => $student->id,
            'client_uuid' => '44444444-4444-4444-8444-444444444444',
            'course_id' => 'math',
            'occurred_at' => '2026-07-09T10:00:00+02:00',
        ]);
        EloInteraction::create([
            'user_id' => $student->id,
            'block_id' => 'b1',
            'course_id' => 'math',
            'score' => 1.0,
            'confirmed_at' => '2026-07-09T10:00:30+02:00',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/admin/users/{$student->id}/work-time")->assertOk();

        $data = $res->json('data');
        $this->assertGreaterThan(0, $data['total_seconds']);
        $this->assertArrayHasKey('math', $data['by_course']);
        $this->assertArrayHasKey('2026-07-09', $data['by_day']);
    }

    public function test_non_admin_cannot_read_another_students_work_time(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($other);

        $this->getJson("/api/admin/users/{$student->id}/work-time")->assertForbidden();
    }
}
