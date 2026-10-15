<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * BR-BNNASY: one audit log for Advanced User Management — the log itself.
 *
 * What a row carries (actor, role, source flow, scope, device), that it is
 * append-only with a configurable retention, and the admin read side: the
 * filtered list, the capped CSV/JSON export, and that only an admin gets in.
 * Which actions write rows is pinned in AuditLogEventsTest.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function row(array $attributes = []): AuditLog
    {
        return AuditLog::create($attributes + [
            'action' => 'classroom.updated',
            'source' => AuditLog::SOURCE_ADMIN,
        ]);
    }

    // ── What a row carries ──────────────────────────────────────────────────

    public function test_a_panel_action_records_actor_role_source_scope_and_device(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);
        $classroom = Classroom::create(['name' => '6.A', 'school_id' => $school->id]);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom->teachers()->attach($teacher->id);

        Sanctum::actingAs($teacher);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh)')
            ->putJson("/api/admin/classrooms/{$classroom->id}", ['name' => '6.B'])
            ->assertOk();

        $log = AuditLog::where('action', 'classroom.updated')->sole();

        $this->assertSame($teacher->id, $log->actor_id);
        $this->assertSame('teacher', $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_ADMIN, $log->source);
        $this->assertSame('classroom', $log->target_type);
        $this->assertSame($classroom->id, $log->target_id);
        $this->assertSame($classroom->id, $log->classroom_id);
        $this->assertSame($school->id, $log->school_id);
        $this->assertSame(['name' => ['from' => '6.A', 'to' => '6.B']], $log->diff);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('Mozilla/5.0 (Macintosh)', $log->user_agent);
        $this->assertSame(AuditLog::OUTCOME_SUCCESS, $log->outcome);
    }

    public function test_an_admin_key_call_is_recorded_as_the_api_key_with_no_actor(): void
    {
        config(['admin.api_key' => 'secret-key']);

        $this->withHeader('X-Admin-Key', 'secret-key')
            ->postJson('/api/admin/schools', ['name' => 'Gymnázium'])
            ->assertCreated();

        $log = AuditLog::where('action', 'school.created')->sole();

        $this->assertNull($log->actor_id);
        $this->assertSame(AuditLog::ROLE_API_KEY, $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_ADMIN, $log->source);
    }

    public function test_without_a_request_the_row_is_a_system_event_with_no_device(): void
    {
        app(AuditLogger::class)->log('consent.purged', request: false);

        $log = AuditLog::sole();

        $this->assertNull($log->actor_id);
        $this->assertSame(AuditLog::ROLE_SYSTEM, $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_SYSTEM, $log->source);
        $this->assertNull($log->ip);
        $this->assertNull($log->user_agent);
    }

    public function test_secret_fields_are_redacted_in_a_diff_but_keep_their_on_off_state(): void
    {
        $this->assertSame(
            ['from' => null, 'to' => AuditLogger::REDACTED_VALUE],
            AuditLogger::pair('join_code', null, 'AB12'),
        );
        $this->assertSame(
            ['from' => AuditLogger::REDACTED_VALUE, 'to' => null],
            AuditLogger::pair('login_code', 'ABC123', null),
        );
        $this->assertSame(['from' => 'a', 'to' => 'b'], AuditLogger::pair('name', 'a', 'b'));
    }

    // ── Append-only, retention ──────────────────────────────────────────────

    public function test_a_row_cannot_be_updated(): void
    {
        $log = $this->row();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'classroom.deleted']);
    }

    public function test_a_row_cannot_be_deleted(): void
    {
        $log = $this->row();

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_prune_drops_rows_past_the_configured_retention_only(): void
    {
        config(['audit.retention_days' => 30]);

        $this->travelTo(now()->subDays(31));
        $old = $this->row();
        $this->travelBack();
        $recent = $this->row();

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }

    public function test_zero_retention_keeps_everything(): void
    {
        config(['audit.retention_days' => 0]);

        $this->travelTo(now()->subYears(5));
        $old = $this->row();
        $this->travelBack();

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
    }

    // ── Admin read side ─────────────────────────────────────────────────────

    public function test_admin_lists_rows_newest_first_with_the_actor(): void
    {
        $actor = User::factory()->create(['role' => 'teacher', 'name' => 'Jana']);
        $this->travelTo(now()->subHour());
        $first = $this->row(['actor_id' => $actor->id, 'actor_role' => 'teacher']);
        $this->travelBack();
        $second = $this->row(['action' => 'school.created']);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/audit-logs')->assertOk();

        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $this->assertSame('Jana', $response->json('data.1.actor.name'));
        $this->assertSame(['classroom.updated', 'school.created'], $response->json('meta.actions'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_a_deleted_actor_keeps_its_id_on_the_row(): void
    {
        $actor = User::factory()->create(['role' => 'teacher']);
        $this->row(['actor_id' => $actor->id]);
        $actor->delete();

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.actor_id', $actor->id)
            ->assertJsonPath('data.0.actor', null);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->row(['action' => 'classroom.created', 'classroom_id' => 1, 'actor_role' => 'teacher']);
        $this->row(['action' => 'classroom.deleted', 'classroom_id' => 2, 'actor_role' => 'admin']);
        $this->row(['action' => 'school.created', 'school_id' => 9, 'outcome' => AuditLog::OUTCOME_DENIED]);
        $this->row(['action' => 'consent.revoked', 'source' => AuditLog::SOURCE_SELF_SERVICE]);

        Sanctum::actingAs($this->admin());

        $actions = fn (string $query) => array_column($this->getJson('/api/admin/audit-logs?' . $query)->assertOk()->json('data'), 'action');

        $this->assertEqualsCanonicalizing(['classroom.created', 'classroom.deleted'], $actions('action=classroom.*'));
        $this->assertSame(['school.created'], $actions('action=school.created'));
        $this->assertSame(['classroom.deleted'], $actions('classroom_id=2'));
        $this->assertSame(['school.created'], $actions('school_id=9'));
        $this->assertSame(['classroom.created'], $actions('actor_role=teacher'));
        $this->assertSame(['school.created'], $actions('outcome=denied'));
        $this->assertSame(['consent.revoked'], $actions('source=self_service'));
    }

    public function test_the_date_filter_uses_local_days(): void
    {
        config(['work.timezone' => 'Europe/Prague']);

        // 23:30 UTC on the 1st is already the 2nd in Prague.
        $this->travelTo('2026-09-01 23:30:00');
        $this->row(['action' => 'classroom.created']);
        $this->travelTo('2026-09-01 12:00:00');
        $this->row(['action' => 'classroom.deleted']);
        $this->travelBack();

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/audit-logs?from=2026-09-02&to=2026-09-02')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'classroom.created');
    }

    public function test_a_malformed_action_filter_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/audit-logs?action=' . urlencode('%'))->assertStatus(422);
    }

    public function test_a_teacher_cannot_read_the_log(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/admin/audit-logs/export')->assertForbidden();
    }

    public function test_csv_export_contains_the_filtered_rows_and_is_itself_logged(): void
    {
        $this->row(['action' => 'classroom.created', 'diff' => ['name' => ['from' => null, 'to' => '6.A']]]);
        $this->row(['action' => 'school.created']);

        Sanctum::actingAs($admin = $this->admin());

        $response = $this->get('/api/admin/audit-logs/export?action=classroom.*')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('classroom.created', $csv);
        $this->assertStringContainsString('6.A', $csv);
        $this->assertStringNotContainsString('school.created', $csv);
        // Its own export row is written after the rows are read.
        $this->assertStringNotContainsString('export.audit_log', $csv);

        $log = AuditLog::where('action', 'export.audit_log')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['format' => 'csv', 'rows' => 1, 'filters' => ['action' => 'classroom.*']], $log->meta);
    }

    public function test_json_export_is_machine_readable(): void
    {
        $this->row(['action' => 'user.role_changed', 'diff' => ['role' => ['from' => 'teacher', 'to' => 'student']]]);

        Sanctum::actingAs($this->admin());

        $rows = json_decode(
            $this->get('/api/admin/audit-logs/export?format=json')->assertOk()->streamedContent(),
            true,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('user.role_changed', $rows[0]['action']);
        $this->assertSame(['role' => ['from' => 'teacher', 'to' => 'student']], $rows[0]['diff']);
    }

    public function test_an_export_over_the_cap_is_refused_and_not_logged(): void
    {
        config(['audit.export_max_rows' => 2]);
        $this->row();
        $this->row();
        $this->row();

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/audit-logs/export')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Zužte'));

        $this->assertSame(0, AuditLog::where('action', 'export.audit_log')->count());
    }
}
