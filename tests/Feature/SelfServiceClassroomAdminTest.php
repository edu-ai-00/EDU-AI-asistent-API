<?php

namespace Tests\Feature;

use App\Mail\ConsentRevokedMail;
use App\Models\Classroom;
use App\Models\Consent;
use App\Models\Guardian;
use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BR-8FDF37 Task 17: admins need to browse and offboard guardian-owned
 * self-service classes without them polluting the ordinary school classroom
 * list (AdminClassroomController::index applied no type filter before this).
 *
 * "Delete" on a self-service classroom does not erase data on the spot — it
 * revokes the guardian's consent through the exact same path their own
 * magic-link management page uses (ConsentService::revoke()). That locks the
 * children out immediately but only *schedules* erasure for
 * consent:purge-revoked after the grace period, mails the guardian, and
 * keeps a full audit trail — so an admin's mistaken click is recoverable for
 * config('consent.purge_after_days') days, not an instant, silent, one-way
 * deletion.
 */
class SelfServiceClassroomAdminTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);

        return $admin->createToken('a')->plainTextToken;
    }

    private function teacherToken(): string
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'teacher@example.test']);

        return $teacher->createToken('t')->plainTextToken;
    }

    /** @return array{0: Classroom, 1: Guardian, 2: Consent, 3: User, 4: string} classroom, guardian, consent, child, child's plaintext login code */
    private function selfServiceClass(): array
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);

        $consent = Consent::startFor($guardian, 'cs', 'CZ');
        $consent->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();

        $classroom = Classroom::create([
            'name' => "Samoobsluha #{$guardian->id}",
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
        ]);

        $code = StudentCodes::generate();
        $child = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash($code),
        ]);
        $child->classrooms()->attach($classroom->id);

        return [$classroom, $guardian, $consent, $child, $code];
    }

    public function test_default_listing_hides_self_service_classes(): void
    {
        Classroom::create(['name' => '5.B']);
        $this->selfServiceClass();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->getJson('/api/admin/classrooms');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', '5.B');
    }

    public function test_type_filter_returns_self_service_classes_with_guardian_email(): void
    {
        Classroom::create(['name' => '5.B']);
        $this->selfServiceClass();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->getJson('/api/admin/classrooms?type=self_service');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'self_service');
        $response->assertJsonPath('data.0.guardian_email', 'rodic@example.com');
        $response->assertJsonPath('data.0.students_count', 1);
    }

    public function test_teacher_never_sees_self_service_classes_in_either_view(): void
    {
        Classroom::create(['name' => '5.B']);
        $this->selfServiceClass();

        $token = $this->teacherToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/classrooms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/classrooms?type=self_service')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_offboarding_revokes_consent_and_blocks_login_immediately(): void
    {
        Mail::fake();

        [$classroom, $guardian, $consent, $child, $code] = $this->selfServiceClass();
        $childTokenModel = $child->createToken('t')->accessToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->deleteJson("/api/admin/classrooms/{$classroom->id}")
            ->assertOk();

        // Nothing was erased — only revoked and scheduled.
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseHas('users', ['id' => $child->id]);
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id]);

        $fresh = $consent->fresh();
        $this->assertSame(Consent::STATUS_REVOKED, $fresh->status);
        $this->assertNotNull($fresh->revoked_at);
        $this->assertNotNull($fresh->purge_after);
        $this->assertTrue($fresh->purge_after->isFuture());

        // The child's existing session is dead — checked against the token
        // table directly rather than with a second authenticated HTTP call
        // in this same test: Sanctum's guard instance memoizes the resolved
        // user for the lifetime of the test's application container, so a
        // later request reusing a *different* bearer token can spuriously
        // come back as whichever user authenticated first (the admin here),
        // regardless of that second token's validity — a test-harness
        // artifact, not something CodeController/AuthController do wrong.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $childTokenModel->id]);

        // A fresh, unauthenticated login attempt with the same code is
        // rejected too — new logins are blocked, not just the old session.
        $this->postJson('/api/resolve-code', ['code' => $code])
            ->assertStatus(403);

        // The guardian is told, same as a self-service revocation.
        Mail::assertSent(ConsentRevokedMail::class);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'revoked']);
    }

    public function test_purge_command_erases_everything_after_the_grace_period(): void
    {
        Mail::fake();

        [$classroom, $guardian, $consent, $child] = $this->selfServiceClass();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->deleteJson("/api/admin/classrooms/{$classroom->id}")
            ->assertOk();

        // Nothing to erase yet — the grace period has not elapsed.
        $this->artisan('consent:purge-revoked')->assertExitCode(0);
        $this->assertDatabaseHas('users', ['id' => $child->id]);

        // Fast-forward past purge_after and let the scheduled command finish
        // the job the admin's revoke started.
        $this->travel((int) config('consent.purge_after_days') + 1)->days();

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseMissing('users', ['id' => $child->id]);
        $this->assertDatabaseMissing('guardians', ['id' => $guardian->id]);
        $this->assertDatabaseMissing('consents', ['id' => $consent->id]);
    }

    public function test_offboarding_an_already_revoked_class_just_clears_the_stale_row(): void
    {
        [$classroom, $guardian, $consent, $child] = $this->selfServiceClass();

        // The guardian already revoked (e.g. via their own magic link)
        // before an admin ever touched this — nothing is governing anymore,
        // so there is nothing left to lock out.
        $consent->forceFill([
            'status' => Consent::STATUS_REVOKED,
            'revoked_at' => now(),
            'purge_after' => now()->addDays(30),
        ])->save();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->deleteJson("/api/admin/classrooms/{$classroom->id}");

        $response->assertOk();

        // The stale listing row is gone...
        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);

        // ...but nothing about the already-scheduled erasure changed: no
        // shortcut around the grace period, no second revocation email, the
        // child and guardian are untouched and still due on their original
        // purge_after.
        $this->assertDatabaseHas('users', ['id' => $child->id]);
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id]);
        $this->assertDatabaseHas('consents', [
            'id' => $consent->id,
            'status' => Consent::STATUS_REVOKED,
        ]);
    }

    public function test_deleting_a_school_class_still_only_removes_its_own_students(): void
    {
        $classroom = Classroom::create(['name' => '5.B']);
        $student = User::create([
            'name' => 'Jana Nováková',
            'login_code' => 'ABC123',
            'login_code_hash' => StudentCodes::hash('ABC123'),
        ]);
        $student->classrooms()->attach($classroom->id);

        [, , , $unrelatedChild] = $this->selfServiceClass();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken()])
            ->deleteJson("/api/admin/classrooms/{$classroom->id}")
            ->assertOk();

        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $unrelatedChild->id]);
    }
}
