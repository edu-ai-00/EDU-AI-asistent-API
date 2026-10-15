<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\Course;
use App\Models\Guardian;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-BNNASY: which AUM actions write to the unified audit log.
 *
 * One test per event family the brief lists as the minimum: class CRUD and
 * membership, user invite / role changes, class codes (set, change, use),
 * consent events, merges, exports and course publishing. Each pins the action
 * key, the target and the diff/meta an auditor would read.
 */
class AuditLogEventsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function actions(): array
    {
        return AuditLog::orderBy('id')->pluck('action')->all();
    }

    private function course(string $status = 'draft'): Course
    {
        return Course::create([
            'course_id' => 'course-x',
            'name' => 'Zlomky',
            'version' => 1,
            'status' => $status,
            'language' => 'cs',
            'data' => [],
        ]);
    }

    // ── Classes: CRUD, roster, membership ───────────────────────────────────

    public function test_creating_a_class_logs_it_with_its_roster_and_a_redacted_code(): void
    {
        $admin = $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/classrooms', [
            'name' => '6.A',
            'join_code' => 'ab12',
            'students' => [['name' => 'Eva', 'login_code' => 'ABC234']],
        ])->assertCreated()->json('data.id');

        $created = AuditLog::where('action', 'classroom.created')->sole();
        $this->assertSame($id, $created->target_id);
        $this->assertSame(['from' => null, 'to' => '6.A'], $created->diff['name']);
        $this->assertSame(['from' => [], 'to' => [$admin->id]], $created->diff['teacher_ids']);
        $this->assertCount(1, $created->meta['student_ids']);

        $code = AuditLog::where('action', 'classroom.join_code_changed')->sole();
        $this->assertSame(['join_code' => ['from' => null, 'to' => AuditLogger::REDACTED_VALUE]], $code->diff);
        $this->assertStringNotContainsString('AB12', json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('ABC234', json_encode(AuditLog::all()->toArray()));
    }

    public function test_class_update_splits_rename_roster_and_code_into_their_own_events(): void
    {
        $this->actingAsAdmin();
        $classroom = Classroom::create(['name' => '6.A', 'join_code' => 'AB12']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->putJson("/api/admin/classrooms/{$classroom->id}", [
            'name' => '6.B',
            'join_code' => null,
            'teacher_ids' => [$teacher->id],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['classroom.teachers_changed', 'classroom.updated', 'classroom.join_code_changed'],
            $this->actions(),
        );
        $this->assertSame(
            ['join_code' => ['from' => AuditLogger::REDACTED_VALUE, 'to' => null]],
            AuditLog::where('action', 'classroom.join_code_changed')->sole()->diff,
        );
        $this->assertSame(
            ['teacher_ids' => ['from' => [], 'to' => [$teacher->id]]],
            AuditLog::where('action', 'classroom.teachers_changed')->sole()->diff,
        );
    }

    public function test_saving_a_class_unchanged_logs_nothing(): void
    {
        $this->actingAsAdmin();
        $classroom = Classroom::create(['name' => '6.A']);

        $this->putJson("/api/admin/classrooms/{$classroom->id}", ['name' => '6.A'])->assertOk();

        $this->assertSame([], $this->actions());
    }

    public function test_adding_students_courses_and_deleting_a_class_are_logged(): void
    {
        $this->actingAsAdmin();
        $classroom = Classroom::create(['name' => '6.A']);
        $course = $this->course();

        $this->postJson("/api/admin/classrooms/{$classroom->id}/students", [
            'students' => [['name' => 'Eva', 'login_code' => 'ABC234']],
        ])->assertCreated();
        $this->postJson("/api/admin/classrooms/{$classroom->id}/courses", ['course_ids' => [$course->id]])->assertOk();
        // Assigning the same course again attaches nothing new.
        $this->postJson("/api/admin/classrooms/{$classroom->id}/courses", ['course_ids' => [$course->id]])->assertOk();
        $this->deleteJson("/api/admin/classrooms/{$classroom->id}/courses/{$course->id}")->assertOk();
        $this->deleteJson("/api/admin/classrooms/{$classroom->id}")->assertOk();

        $this->assertSame([
            'classroom.students_added',
            'classroom.courses_assigned',
            'classroom.course_removed',
            'classroom.deleted',
        ], $this->actions());

        $deleted = AuditLog::where('action', 'classroom.deleted')->sole();
        $this->assertSame('6.A', $deleted->meta['name']);
        $this->assertCount(1, $deleted->meta['erased_student_ids']);
        $this->assertSame([$course->id], AuditLog::where('action', 'classroom.courses_assigned')->sole()->meta['course_ids']);
    }

    public function test_changing_a_students_classes_logs_the_membership_diff(): void
    {
        $this->actingAsAdmin();
        $a = Classroom::create(['name' => '6.A']);
        $b = Classroom::create(['name' => '6.B']);
        $student = User::factory()->create(['role' => 'student']);
        $student->classrooms()->attach($a->id);

        $this->putJson("/api/admin/users/{$student->id}", ['classroom_ids' => [$b->id]])->assertOk();

        $log = AuditLog::where('action', 'user.classrooms_changed')->sole();
        $this->assertSame($student->id, $log->target_id);
        $this->assertSame(['classroom_ids' => ['from' => [$a->id], 'to' => [$b->id]]], $log->diff);
    }

    // ── Schools ─────────────────────────────────────────────────────────────

    public function test_school_lifecycle_is_logged(): void
    {
        $this->actingAsAdmin();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom = Classroom::create(['name' => '6.A']);

        $id = $this->postJson('/api/admin/schools', ['name' => 'ZŠ', 'teacher_ids' => [$teacher->id]])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/admin/schools/{$id}", ['name' => 'ZŠ Komenského', 'teacher_ids' => []])->assertOk();
        $this->putJson("/api/admin/schools/{$id}/classrooms", ['classroom_ids' => [$classroom->id]])->assertOk();
        $this->deleteJson("/api/admin/schools/{$id}")->assertOk();

        $this->assertSame([
            'school.created',
            'school.updated',
            'school.teachers_changed',
            'school.classrooms_changed',
            'school.deleted',
        ], $this->actions());

        $this->assertSame([$teacher->id], AuditLog::where('action', 'school.created')->sole()->diff['teacher_ids']['to']);
        $this->assertSame([$classroom->id], AuditLog::where('action', 'school.deleted')->sole()->meta['unfiled_classroom_ids']);
        $this->assertTrue(AuditLog::where('action', 'school.updated')->where('school_id', $id)->exists());
    }

    // ── Users: invite, role changes ─────────────────────────────────────────

    public function test_team_invite_role_change_and_removal_are_logged(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/team', ['name' => 'Jana', 'email' => 'jana@skola.cz', 'role' => 'teacher'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/admin/team/{$id}", ['role' => 'admin', 'name' => 'Jana N.'])->assertOk();
        $this->deleteJson("/api/admin/team/{$id}")->assertOk();

        $this->assertSame(['user.invited', 'user.role_changed', 'user.updated', 'user.role_changed'], $this->actions());

        $removal = AuditLog::where('action', 'user.role_changed')->orderByDesc('id')->first();
        $this->assertSame(['role' => ['from' => 'admin', 'to' => 'student']], $removal->diff);
        $this->assertTrue($removal->meta['removed_from_team']);
    }

    public function test_promoting_an_existing_student_logs_the_role_change_and_the_invite(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        User::factory()->create(['role' => 'student', 'email' => 'eva@skola.cz', 'name' => 'Eva']);

        $this->postJson('/api/admin/team', ['name' => 'Eva', 'email' => 'eva@skola.cz', 'role' => 'teacher'])
            ->assertCreated();

        $this->assertSame(['user.role_changed', 'user.invited'], $this->actions());
        $this->assertTrue(AuditLog::where('action', 'user.invited')->sole()->meta['existing_account']);
    }

    // ── Class codes: use ────────────────────────────────────────────────────

    public function test_joining_by_class_code_is_logged_as_the_student_in_self_service(): void
    {
        $classroom = Classroom::create(['name' => '6.A', 'join_code' => 'AB12']);
        $student = User::factory()->create(['role' => 'student', 'email' => 'jana@example.com']);
        Sanctum::actingAs($student);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ab12'])->assertOk();

        $log = AuditLog::where('action', 'classroom.joined')->sole();
        $this->assertSame($student->id, $log->actor_id);
        $this->assertSame('student', $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_SELF_SERVICE, $log->source);
        $this->assertSame($classroom->id, $log->classroom_id);
        $this->assertSame(['via' => 'class_code'], $log->meta);
    }

    public function test_joining_by_student_code_is_logged_with_the_merge_it_made(): void
    {
        $classroom = Classroom::create(['name' => '6.A']);
        $placeholder = User::create([
            'name' => 'Eva',
            'login_code' => 'ABC234',
            'login_code_hash' => StudentCodes::hash('ABC234'),
        ]);
        $placeholder->classrooms()->attach($classroom->id);
        $student = User::factory()->create(['role' => 'student', 'email' => 'eva@example.com']);
        Sanctum::actingAs($student);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC234'])->assertOk();

        $log = AuditLog::where('action', 'classroom.joined')->sole();
        $this->assertSame('student_code', $log->meta['via']);
        $this->assertSame($placeholder->id, $log->meta['placeholder_user_id']);
        $this->assertNotNull($log->meta['merge_id']);
    }

    // ── Merges ──────────────────────────────────────────────────────────────

    public function test_an_admin_merge_is_logged_against_the_surviving_account(): void
    {
        $this->actingAsAdmin();
        $source = User::factory()->create(['role' => 'student', 'email' => null]);
        $target = User::factory()->create(['role' => 'student']);

        $mergeId = $this->postJson('/api/admin/users/merge', ['source_id' => $source->id, 'target_id' => $target->id])
            ->assertCreated()->json('data.merge_id');

        $log = AuditLog::where('action', 'user.merged')->sole();
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['merge_id' => $mergeId, 'source_user_id' => $source->id], $log->meta);
    }

    // ── Exports ─────────────────────────────────────────────────────────────

    public function test_data_exports_record_who_exported_what(): void
    {
        $admin = $this->actingAsAdmin();
        $classroom = Classroom::create(['name' => '6.A']);
        $course = $this->course();

        $this->get("/api/admin/activity/export?classroom_id={$classroom->id}&from=2026-09-01&to=2026-09-30")->assertOk();
        $this->get('/api/admin/progress/course-x/answers/export')->assertOk();
        $this->get('/api/admin/elo/interactions/course/course-x/export')->assertOk();

        $this->assertSame(['export.activity', 'export.course_answers', 'export.elo_interactions'], $this->actions());

        $activity = AuditLog::where('action', 'export.activity')->sole();
        $this->assertSame($admin->id, $activity->actor_id);
        $this->assertSame($classroom->id, $activity->classroom_id);
        $this->assertSame('2026-09-01', $activity->meta['from']);

        $answers = AuditLog::where('action', 'export.course_answers')->sole();
        $this->assertSame('course', $answers->target_type);
        $this->assertSame($course->id, $answers->target_id);
    }

    // ── Course publishing ───────────────────────────────────────────────────

    public function test_publishing_and_unpublishing_a_course_is_logged_other_edits_are_not(): void
    {
        $this->actingAsAdmin();
        $course = $this->course('draft');

        $this->putJson("/api/courses/{$course->id}", ['name' => 'Zlomky 2'])->assertOk();
        $this->putJson("/api/courses/{$course->id}", ['status' => 'published'])->assertOk();
        $this->putJson("/api/courses/{$course->id}", ['status' => 'private'])->assertOk();
        $this->deleteJson("/api/courses/{$course->id}")->assertOk();
        $this->postJson("/api/courses/{$course->id}/restore")->assertOk();

        $this->assertSame(['course.published', 'course.unpublished', 'course.deleted', 'course.restored'], $this->actions());
        $this->assertSame(
            ['status' => ['from' => 'draft', 'to' => 'published']],
            AuditLog::where('action', 'course.published')->sole()->diff,
        );
    }

    // ── Consent / child onboarding ──────────────────────────────────────────

    public function test_consent_events_land_in_the_unified_log_as_the_guardian_without_device_data(): void
    {
        Mail::fake();

        $this->withHeader('User-Agent', 'GuardianPhone/1.0')
            ->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk();

        $source = ConsentAuditLog::where('event', 'requested')->sole();
        $log = AuditLog::where('action', 'consent.requested')->sole();

        $this->assertSame('consent', $log->target_type);
        $this->assertSame($source->consent_id, $log->target_id);
        $this->assertSame(AuditLog::ROLE_GUARDIAN, $log->actor_role);
        $this->assertNull($log->actor_id);
        $this->assertSame(AuditLog::SOURCE_SELF_SERVICE, $log->source);
        $this->assertSame($source->id, $log->meta['consent_audit_log_id']);
        $this->assertSame($source->guardian_id, $log->meta['guardian_id']);
        $this->assertSame('CZ', $log->meta['country']);
        // The evidentiary row keeps the device; the mirror never copies it,
        // so consent:purge-revoked has only one place to scrub.
        $this->assertSame('GuardianPhone/1.0', $source->user_agent);
        $this->assertNull($log->ip);
        $this->assertNull($log->user_agent);
    }

    public function test_every_consent_audit_row_is_mirrored_including_system_ones(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');

        ConsentAuditLog::record($consent, 'purged', null, ['children' => 0]);

        $log = AuditLog::where('action', 'consent.purged')->sole();
        $this->assertSame(AuditLog::ROLE_SYSTEM, $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_SYSTEM, $log->source);
        $this->assertSame(0, $log->meta['children']);
        $this->assertSame(ConsentAuditLog::count(), AuditLog::where('action', 'like', 'consent.%')->count());
    }

    public function test_an_admin_revoking_a_family_class_is_recorded_as_the_admin(): void
    {
        Mail::fake();
        $admin = $this->actingAsAdmin();

        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');
        $consent->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();
        $classroom = Classroom::create(['name' => 'Rodina', 'type' => 'self_service', 'guardian_id' => $guardian->id]);

        $this->deleteJson("/api/admin/classrooms/{$classroom->id}")->assertOk();

        $log = AuditLog::where('action', 'consent.revoked')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('admin', $log->actor_role);
        $this->assertSame(AuditLog::SOURCE_ADMIN, $log->source);
    }
}
