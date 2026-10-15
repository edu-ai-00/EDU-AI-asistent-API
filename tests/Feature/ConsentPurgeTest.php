<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Consent;
use App\Models\ConsentCode;
use App\Models\Guardian;
use App\Models\User;
use App\Models\UserProgress;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsentPurgeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Consent, 1: User} */
    private function revokedConsentWithChild(\DateTimeInterface $purgeAfter, string $email = 'rodic@example.com'): array
    {
        $guardian = Guardian::create(['email' => $email, 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');
        $consent->forceFill([
            'status' => Consent::STATUS_REVOKED,
            'revoked_at' => now()->subDays(31),
            'purge_after' => $purgeAfter,
        ])->save();

        // The classroom matters: classrooms.guardian_id is restrictOnDelete, so
        // a fixture without one would let a broken purge command pass its test.
        $classroom = Classroom::create([
            'name' => $guardian->email,
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
        ]);

        $child = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash(StudentCodes::generate()),
        ]);
        $child->classrooms()->attach($classroom->id);

        UserProgress::create([
            'user_id' => $child->id,
            'course_id' => 'demo-course',
            'lesson_id' => 'l1',
            'completed' => true,
        ]);
        $child->createToken('t');

        return [$consent, $child];
    }

    public function test_purge_erases_child_and_all_dependent_rows(): void
    {
        [$consent, $child] = $this->revokedConsentWithChild(now()->subDay());

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $child->id]);
        $this->assertDatabaseMissing('user_progress', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $child->id,
            'tokenable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('classrooms', ['guardian_id' => $consent->guardian_id]);
        $this->assertDatabaseMissing('guardians', ['id' => $consent->guardian_id]);
    }

    public function test_purge_keeps_the_audit_trail_after_the_data_is_gone(): void
    {
        [$consent] = $this->revokedConsentWithChild(now()->subDay());

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'purged']);
        $this->assertTrue(
            \App\Models\ConsentAuditLog::where('event', 'purged')->exists(),
            'the purge must remain provable once the consent row is gone'
        );
    }

    /**
     * Decision 10: the audit trail is append-only and never deleted, but the
     * guardian's ip/user_agent are personal data and must not outlive the
     * erasure they describe. A surviving audit row (e.g. the original
     * 'requested' event, captured with a real IP before revocation) must have
     * both scrubbed once the purge runs — while the row itself, its event
     * name and its timestamp remain, since those are what make the consent
     * provable.
     *
     * I5: the erasure branch's scrub is scoped to this guardian's
     * guardian_id (B1 — see
     * test_purge_scrubs_an_expired_consents_audit_row_during_full_erasure()
     * for why it can't be narrower than that). A second, unrelated
     * guardian's audit rows — for a guardian who is not part of this purge
     * run at all — must be completely untouched; dropping the
     * where('guardian_id', ...) filter would scrub the whole table,
     * including IPs on live, still-disputable consents.
     */
    public function test_purge_scrubs_ip_and_user_agent_from_surviving_audit_rows(): void
    {
        [$consent] = $this->revokedConsentWithChild(now()->subDay());

        $preExistingLog = \App\Models\ConsentAuditLog::create([
            'consent_id' => $consent->id,
            'guardian_id' => $consent->guardian_id,
            'event' => 'requested',
            'ip' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0 (Test Browser)',
        ]);

        $otherGuardian = Guardian::create(['email' => 'nesouvisi@example.com', 'locale' => 'cs']);
        $otherConsent = Consent::startFor($otherGuardian, 'cs', 'CZ');
        $otherConsent->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();
        $unrelatedLog = \App\Models\ConsentAuditLog::create([
            'consent_id' => $otherConsent->id,
            'guardian_id' => $otherGuardian->id,
            'event' => 'requested',
            'ip' => '192.0.2.99',
            'user_agent' => 'Mozilla/5.0 (Unrelated Browser)',
        ]);

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseHas('consent_audit_logs', [
            'id' => $preExistingLog->id,
            'event' => 'requested',
            'ip' => null,
            'user_agent' => null,
        ]);
        $this->assertDatabaseHas('consent_audit_logs', [
            'id' => $unrelatedLog->id,
            'event' => 'requested',
            'ip' => '192.0.2.99',
            'user_agent' => 'Mozilla/5.0 (Unrelated Browser)',
        ]);
    }

    /**
     * B1: deleting the guardian in the erasure branch cascades away every
     * consent they hold, not just the ones that were due — including an
     * EXPIRED row. supersedePendingConsents() marks a stale consent EXPIRED
     * on every repeat consent request, and supersedeConfirmedConsents()
     * does the same on every second confirm cycle, so this is not an edge
     * case: any parent who ever lost their code/email and asked again has
     * one. That EXPIRED row's own 'requested' audit event carries a real
     * IP that a consent_id-scoped scrub can never reach once the guardian
     * is deleted — both consent_id and guardian_id go null on that row via
     * nullOnDelete, permanently severing it from any future cleanup. The
     * fix scrubs by guardian_id, covering every consent the guardian ever
     * had, before the guardian — and with it the linkage — disappears.
     */
    public function test_purge_scrubs_an_expired_consents_audit_row_during_full_erasure(): void
    {
        [$consent, $child] = $this->revokedConsentWithChild(now()->subDay(), 'ztraceny@example.com');

        $expiredConsent = Consent::startFor($consent->guardian, 'cs', 'CZ');
        $expiredConsent->forceFill(['status' => Consent::STATUS_EXPIRED])->save();
        $expiredLog = \App\Models\ConsentAuditLog::create([
            'consent_id' => $expiredConsent->id,
            'guardian_id' => $consent->guardian_id,
            'event' => 'requested',
            'ip' => '203.0.113.77',
            'user_agent' => 'Mozilla/5.0 (Lost Code Retry)',
        ]);

        $otherGuardian = Guardian::create(['email' => 'jiny@example.com', 'locale' => 'cs']);
        $otherConsent = Consent::startFor($otherGuardian, 'cs', 'CZ');
        $otherConsent->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();
        $unrelatedLog = \App\Models\ConsentAuditLog::create([
            'consent_id' => $otherConsent->id,
            'guardian_id' => $otherGuardian->id,
            'event' => 'requested',
            'ip' => '192.0.2.55',
            'user_agent' => 'Mozilla/5.0 (Unrelated)',
        ]);

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $child->id]);
        $this->assertDatabaseHas('consent_audit_logs', [
            'id' => $expiredLog->id,
            'event' => 'requested',
            'ip' => null,
            'user_agent' => null,
        ]);
        // The unrelated, still-live guardian must be completely untouched.
        $this->assertDatabaseHas('consent_audit_logs', [
            'id' => $unrelatedLog->id,
            'ip' => '192.0.2.55',
            'user_agent' => 'Mozilla/5.0 (Unrelated)',
        ]);
    }

    public function test_purge_leaves_children_alone_inside_the_grace_period(): void
    {
        [, $child] = $this->revokedConsentWithChild(now()->addDays(10));

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $child->id]);
    }

    public function test_purge_never_touches_a_confirmed_consent(): void
    {
        $guardian = Guardian::create(['email' => 'ok@example.com', 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');
        $consent->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();

        $child = User::create([
            'name' => 'Veselý tygr',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash(StudentCodes::generate()),
        ]);

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $child->id]);
    }

    /**
     * I4: the previous version of this test only used a live row and a
     * 45-day-old row against a 30-day default cutoff, so replacing $cutoff
     * with now() (dropping everything already expired, regardless of how
     * long ago) would still have passed. $insideWindow — expired 20 days
     * ago, inside the 30-day retention — pins the actual cutoff value: it
     * must be pruned by neither today's date nor "any expiry", only by one
     * more than 30 days old.
     */
    public function test_prune_codes_drops_only_long_dead_rows(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');

        [, $live] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        $insideWindow = ConsentCode::create([
            'consent_id' => $consent->id,
            'purpose' => ConsentCode::PURPOSE_CONFIRM,
            'code_hash' => str_repeat('a', 64),
            'expires_at' => now()->subDays(20),
        ]);
        $stale = ConsentCode::create([
            'consent_id' => $consent->id,
            'purpose' => ConsentCode::PURPOSE_CONFIRM,
            'code_hash' => str_repeat('b', 64),
            'expires_at' => now()->subDays(45),
        ]);

        $this->artisan('consent:prune-codes')->assertExitCode(0);

        $this->assertDatabaseHas('consent_codes', ['id' => $live->id]);
        $this->assertDatabaseHas('consent_codes', ['id' => $insideWindow->id]);
        $this->assertDatabaseMissing('consent_codes', ['id' => $stale->id]);
    }

    /**
     * ConsentService::revoke() revokes EVERY currently-CONFIRMED consent for
     * a guardian in one call (its own docblock calls this "belt and braces"
     * for rows that predate the single-governing-consent fix). That can
     * leave one guardian with two REVOKED rows sharing the same purge_after,
     * both due in the same run. A purge command that deletes the guardian
     * after the first row and only then tries to write a 'purged' audit row
     * for the second — whose consent_id no longer exists once the guardian
     * cascade-deletes it — must not throw a foreign-key violation and abort
     * the whole run (which would silently stop erasing every guardian after
     * it too).
     */
    public function test_purge_handles_a_guardian_with_two_simultaneously_revoked_consents(): void
    {
        $guardian = Guardian::create(['email' => 'dva@example.com', 'locale' => 'cs']);

        $purgeAfter = now()->subDay();
        $first = Consent::startFor($guardian, 'cs', 'CZ');
        $first->forceFill([
            'status' => Consent::STATUS_REVOKED,
            'revoked_at' => now()->subDays(31),
            'purge_after' => $purgeAfter,
        ])->save();
        $second = Consent::startFor($guardian, 'cs', 'CZ');
        $second->forceFill([
            'status' => Consent::STATUS_REVOKED,
            'revoked_at' => now()->subDays(31),
            'purge_after' => $purgeAfter,
        ])->save();

        $child = User::create([
            'name' => 'Tichý jelen',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash(StudentCodes::generate()),
        ]);

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $child->id]);
        $this->assertDatabaseMissing('guardians', ['id' => $guardian->id]);
        // consent_id is nullOnDelete (decision 9/10), so once both consent
        // rows are gone the surviving 'purged' audit rows no longer point
        // back at $first/$second by id — count by event instead, the same
        // way test_purge_keeps_the_audit_trail_after_the_data_is_gone() does.
        $this->assertSame(
            2,
            \App\Models\ConsentAuditLog::where('event', 'purged')->count(),
            'both revoked consents must get their own purged audit row'
        );
    }

    /**
     * C1: a parent can revoke and later re-onboard with the same email —
     * requestConsent() reuses the guardian row via firstOrCreate(). The old
     * REVOKED consent (A) keeps its own purge_after untouched;
     * supersedeConfirmedConsents() only ever retires CONFIRMED rows, never a
     * REVOKED one, so A can still be "due" on day 30 even though the family
     * now holds a perfectly valid CONFIRMED consent (B). Erasing on the
     * strength of A alone would destroy a family whose consent is currently
     * live — the child, the classroom, the guardian, and B itself, since
     * consents.guardian_id cascades on delete. The revoked row is moot: only
     * it should be cleaned up.
     */
    public function test_purge_treats_a_stale_revoked_consent_as_moot_when_the_guardian_has_reconsented(): void
    {
        $guardian = Guardian::create(['email' => 'znovu@example.com', 'locale' => 'cs']);

        $consentA = Consent::startFor($guardian, 'cs', 'CZ');
        $consentA->forceFill([
            'status' => Consent::STATUS_REVOKED,
            'revoked_at' => now()->subDays(31),
            'purge_after' => now()->subDay(),
        ])->save();

        $auditLogA = \App\Models\ConsentAuditLog::create([
            'consent_id' => $consentA->id,
            'guardian_id' => $guardian->id,
            'event' => 'revoked',
            'ip' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 (Old Session)',
        ]);

        $consentB = Consent::startFor($guardian, 'cs', 'CZ');
        $consentB->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();

        $classroom = Classroom::create([
            'name' => $guardian->email,
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
        ]);

        $child = User::create([
            'name' => 'Bystrý orel',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash(StudentCodes::generate()),
        ]);
        $child->classrooms()->attach($classroom->id);

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $child->id]);
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id]);
        $this->assertDatabaseHas('consents', ['id' => $consentB->id]);
        $this->assertDatabaseMissing('consents', ['id' => $consentA->id]);
        $this->assertDatabaseHas('consent_audit_logs', [
            'id' => $auditLogA->id,
            'ip' => null,
            'user_agent' => null,
        ]);
    }

    /**
     * C2: legacy data (predating the is_minor merge guard added alongside
     * this fix) can still hold a user_merges row referencing a minor's id
     * via source_user_id/target_user_id/performed_by — all three
     * restrictOnDelete. StudentDataEraser::erase() then throws when it
     * tries to delete that child. One poisoned guardian must degrade to a
     * skipped guardian — logged, left untouched for the next run — not
     * abort the whole batch and silently strand every guardian queued after
     * it.
     */
    public function test_purge_skips_a_guardian_blocked_by_a_restrict_constraint_and_still_purges_the_rest(): void
    {
        [, $poisonedChild] = $this->revokedConsentWithChild(now()->subDay(), 'poisoned@example.com');

        $otherUser = User::factory()->create(['role' => 'student', 'is_guest' => false]);
        DB::table('user_merges')->insert([
            'source_user_id' => $otherUser->id,
            'target_user_id' => $poisonedChild->id,
            'performed_by' => $otherUser->id,
            'source_snapshot' => '{}',
            'target_before' => '{}',
            'strategy_log' => '{}',
            'created_at' => now(),
        ]);

        [, $healthyChild] = $this->revokedConsentWithChild(now()->subDay(), 'healthy@example.com');

        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        // A failed guardian must be left untouched, not half-deleted.
        $this->assertDatabaseHas('users', ['id' => $poisonedChild->id]);
        $this->assertDatabaseMissing('users', ['id' => $healthyChild->id]);
    }
}
