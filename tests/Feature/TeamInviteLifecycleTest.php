<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Mail\VerificationCodeMail;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BR-XW6CWV: a teacher invite was a fire-and-forget e-mail.
 *
 * POST /admin/team created the account, mailed TeamInviteMail and that was
 * the whole lifecycle — no state to tell a pending invite from someone who
 * already works in the panel, no expiry and no way to send it again. The
 * Notion story ("Učitel: pozvánky + správa účtů učitelů") asks for invited →
 * active → deactivated (+ reactivated), a 7-day expiry, resend without
 * duplicates, an optional school in the invite, and an audit trail.
 *
 * Deactivation itself is BR-Q3ZJ6Q's account switch (AccountDeactivationTest)
 * and the trail is BR-BNNASY's audit log; this covers the invite on top of
 * them and how the two meet in the roster's status.
 */
class TeamInviteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('a')->plainTextToken;
    }

    private function asAdmin(): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken);
    }

    private function invite(array $overrides = []): User
    {
        $this->asAdmin()
            ->postJson('/api/admin/team', array_merge([
                'name' => 'Jana Nováková',
                'email' => 'jana@skola.cz',
                'role' => 'teacher',
            ], $overrides))
            ->assertStatus(201);

        return User::where('email', $overrides['email'] ?? 'jana@skola.cz')->firstOrFail();
    }

    private function signInToAdmin(string $email): \Illuminate\Testing\TestResponse
    {
        $code = VerificationCode::generateFor($email, 10);

        app('auth')->forgetGuards();

        return $this->flushHeaders()
            ->postJson('/api/admin/auth/verify', [
                'email' => $email,
                'code' => $code->code,
            ]);
    }

    private function rosterEntry(int $id): array
    {
        $roster = $this->asAdmin()->getJson('/api/admin/team')->assertStatus(200)->json('data');

        return collect($roster)->firstWhere('id', $id);
    }

    private function auditRow(string $action, User $target): ?AuditLog
    {
        return AuditLog::where('action', $action)
            ->where('target_type', 'user')
            ->where('target_id', $target->id)
            ->latest('id')
            ->first();
    }

    // ─── invite ───────────────────────────────────────────────────────────────

    public function test_a_fresh_invite_is_pending_for_seven_days(): void
    {
        Mail::fake();
        $this->freezeSecond();

        $member = $this->invite();

        $entry = $this->rosterEntry($member->id);
        $this->assertSame('invited', $entry['status']);
        $this->assertSame(now()->addDays(7)->toIso8601String(), $entry['invite_expires_at']);
    }

    public function test_the_invitation_mail_says_until_when_it_is_valid(): void
    {
        Mail::fake();
        $this->freezeSecond();

        $this->invite();

        Mail::assertSent(TeamInviteMail::class, fn (TeamInviteMail $m) => $m->expiresAt?->equalTo(now()->addDays(7)));
    }

    public function test_the_expiry_line_renders_in_every_supported_locale(): void
    {
        $expiresAt = \Illuminate\Support\Carbon::parse('2026-10-06 12:00:00', 'UTC');

        foreach (['cs', 'en', 'sk'] as $locale) {
            $this->app->setLocale($locale);

            $rendered = (new TeamInviteMail('Jana', 'teacher', 'https://admin.test/login', $expiresAt))->render();

            $this->assertStringContainsString('2026', $rendered);
            $this->assertStringNotContainsString('mail.team_invite', $rendered);
        }
    }

    public function test_an_invite_can_assign_the_teacher_to_schools(): void
    {
        Mail::fake();
        $school = School::create(['name' => 'ZŠ Komenského']);

        $member = $this->invite(['school_ids' => [$school->id]]);

        $this->assertEqualsCanonicalizing([$school->id], $member->schools()->pluck('schools.id')->all());
        $this->assertSame([['id' => $school->id, 'name' => 'ZŠ Komenského']], $this->rosterEntry($member->id)['schools']);
        $this->assertSame([$school->id], $this->auditRow('user.invited', $member)->meta['school_ids']);
    }

    public function test_an_unknown_school_is_rejected(): void
    {
        Mail::fake();

        $this->asAdmin()
            ->postJson('/api/admin/team', [
                'name' => 'Jana',
                'email' => 'jana@skola.cz',
                'role' => 'teacher',
                'school_ids' => [999],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'jana@skola.cz']);
    }

    public function test_the_invite_is_audited_with_its_expiry(): void
    {
        Mail::fake();
        $this->freezeSecond();

        $member = $this->invite();

        $row = $this->auditRow('user.invited', $member);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame(now()->addDays(7)->toIso8601String(), $row->meta['invite_expires_at']);
    }

    /**
     * The rule for an e-mail that already belongs to a student (48247f8): the
     * student account is promoted in place — same id, same history — and the
     * audit row says so.
     */
    public function test_inviting_an_existing_student_promotes_that_account_and_records_it(): void
    {
        Mail::fake();
        $student = User::factory()->create(['email' => 'petr@skola.cz', 'role' => 'student']);

        $member = $this->invite(['name' => 'Petr', 'email' => 'petr@skola.cz']);

        $this->assertSame($student->id, $member->id);
        $this->assertSame(1, User::where('email', 'petr@skola.cz')->count());
        $this->assertSame('invited', $member->teamStatus());
        $this->assertTrue($this->auditRow('user.invited', $member)->meta['existing_account']);
    }

    public function test_members_from_before_the_lifecycle_existed_are_active(): void
    {
        $legacy = User::factory()->create(['role' => 'teacher']);

        $this->assertSame('active', $this->rosterEntry($legacy->id)['status']);
    }

    // ─── expiry + acceptance ──────────────────────────────────────────────────

    public function test_an_invite_older_than_seven_days_is_expired(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->travel(8)->days();

        $this->assertSame('expired', $this->rosterEntry($member->id)['status']);
    }

    public function test_an_expired_invite_cannot_be_used_to_sign_in(): void
    {
        Mail::fake();
        $this->invite();

        $this->travel(8)->days();

        $this->signInToAdmin('jana@skola.cz')
            ->assertStatus(403)
            ->assertJsonPath('error', 'invite_expired');
    }

    public function test_an_expired_invite_still_gets_a_code_so_the_panel_can_explain_why(): void
    {
        Mail::fake();
        $this->invite();
        $this->travel(8)->days();

        $this->flushHeaders()->postJson('/api/admin/auth/send-code', ['email' => 'jana@skola.cz'])->assertStatus(200);

        Mail::assertSent(VerificationCodeMail::class, fn ($m) => $m->hasTo('jana@skola.cz'));
    }

    public function test_the_first_sign_in_accepts_the_invite(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);

        $this->assertSame('active', $member->fresh()->teamStatus());
        $this->assertSame('teacher', $this->auditRow('user.invite_accepted', $member)->actor_role);
    }

    /**
     * BR-6UD3NC: the accepted-invite row had no actor — the sign-in it comes
     * from is not authenticated yet, so the log read nobody off the request
     * and the panel showed "bez účtu" where the person's e-mail belongs.
     */
    public function test_the_accepted_invite_names_the_person_who_accepted_it_as_the_actor(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);

        $this->assertSame($member->id, $this->auditRow('user.invite_accepted', $member)->actor_id);

        $this->asAdmin()
            ->getJson('/api/admin/audit-logs?action=user.invite_accepted')
            ->assertStatus(200)
            ->assertJsonPath('data.0.actor.email', 'jana@skola.cz')
            ->assertJsonPath('data.0.actor_role', 'teacher');
    }

    public function test_an_accepted_invite_never_expires_afterwards(): void
    {
        Mail::fake();
        $member = $this->invite();
        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);

        $this->travel(30)->days();

        $this->assertSame('active', $member->fresh()->teamStatus());
        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);
    }

    // ─── resend ───────────────────────────────────────────────────────────────

    public function test_resending_an_expired_invite_reopens_it_without_a_second_account(): void
    {
        Mail::fake();
        $member = $this->invite();
        $this->travel(8)->days();
        $this->freezeSecond();

        $this->asAdmin()
            ->postJson("/api/admin/team/{$member->id}/resend")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.invite_expires_at', now()->addDays(7)->toIso8601String());

        $this->assertSame(1, User::where('email', 'jana@skola.cz')->count());
        Mail::assertSent(TeamInviteMail::class, 2);
        $this->assertNotNull($this->auditRow('user.invite_resent', $member));

        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);
    }

    public function test_an_active_member_has_no_invite_to_resend(): void
    {
        Mail::fake();
        $active = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()->postJson("/api/admin/team/{$active->id}/resend")->assertStatus(422);

        Mail::assertNothingSent();
    }

    // ─── meeting deactivation (BR-Q3ZJ6Q) ──────────────────────────────────────

    public function test_a_deactivated_member_reads_as_deactivated_even_with_an_invite_pending(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->asAdmin()->postJson("/api/admin/users/{$member->id}/deactivate")->assertStatus(200);

        $this->assertSame('deactivated', $this->rosterEntry($member->id)['status']);
        $this->asAdmin()->postJson("/api/admin/team/{$member->id}/resend")->assertStatus(422);
    }

    public function test_reactivating_puts_the_invite_back_where_it_was(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->asAdmin()->postJson("/api/admin/users/{$member->id}/deactivate")->assertStatus(200);
        $this->asAdmin()->postJson("/api/admin/users/{$member->id}/reactivate")->assertStatus(200);

        $this->assertSame('invited', $this->rosterEntry($member->id)['status']);
    }

    // ─── leaving and rejoining the team ───────────────────────────────────────

    /**
     * Someone removed from the team with an invite still open, then put back
     * through the user detail role select — not a new invite — must not be
     * locked out by the old expiry.
     */
    public function test_rejoining_through_the_role_select_does_not_revive_an_old_invite(): void
    {
        Mail::fake();
        $member = $this->invite();
        $this->asAdmin()->deleteJson("/api/admin/team/{$member->id}")->assertStatus(200);

        $this->travel(8)->days();

        $this->asAdmin()->putJson("/api/admin/users/{$member->id}", ['role' => 'teacher'])->assertStatus(200);

        $this->assertSame('active', $member->fresh()->teamStatus());
        $this->signInToAdmin('jana@skola.cz')->assertStatus(200);
    }

    public function test_being_invited_back_opens_a_fresh_invite(): void
    {
        Mail::fake();
        $member = $this->invite();
        $this->asAdmin()->deleteJson("/api/admin/team/{$member->id}")->assertStatus(200);
        $this->travel(8)->days();
        $this->freezeSecond();

        $this->invite();

        $this->assertSame('invited', $member->fresh()->teamStatus());
        $this->assertTrue($member->fresh()->invite_expires_at->equalTo(now()->addDays(7)));
    }

    public function test_switching_between_admin_and_teacher_keeps_the_pending_invite(): void
    {
        Mail::fake();
        $member = $this->invite();

        $this->asAdmin()->putJson("/api/admin/team/{$member->id}", ['role' => 'admin'])->assertStatus(200);

        $this->assertSame('invited', $member->fresh()->teamStatus());
    }
}
