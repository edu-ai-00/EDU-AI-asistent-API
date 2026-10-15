<?php

namespace Tests\Feature;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentManageLinkMail;
use App\Mail\ConsentRequestMail;
use App\Mail\ConsentRevokedMail;
use App\Models\Consent;
use App\Models\ConsentCode;
use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConsentManageTest extends TestCase
{
    use RefreshDatabase;

    /** Drive request+confirm and return the guardian's manage token. */
    private function confirmedConsent(string $email = 'rodic@example.com'): array
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => $email, 'locale' => 'cs', 'country' => 'CZ']);

        $code = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $consent = Consent::latest('id')->first();
        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();

        $token = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$token) {
            $token = basename($mail->manageUrl);

            return true;
        });

        return [$consent->fresh(), $token];
    }

    public function test_manage_page_lists_children_without_revealing_codes(): void
    {
        [, $token] = $this->confirmedConsent();

        $response = $this->getJson("/api/consent/manage/{$token}");

        $response->assertOk();
        $response->assertJsonPath('status', 'confirmed');
        $response->assertJsonCount(3, 'children');
        $this->assertArrayNotHasKey('login_code', $response->json('children.0'));
        $this->assertArrayNotHasKey('login_code_hash', $response->json('children.0'));
    }

    public function test_expired_token_is_rejected(): void
    {
        [, $token] = $this->confirmedConsent();

        ConsentCode::where('purpose', 'manage')
            ->update(['expires_at' => now()->subHour()]);

        $this->getJson("/api/consent/manage/{$token}")->assertStatus(404);
    }

    public function test_regenerate_replaces_the_child_code_and_burns_the_old_one(): void
    {
        [, $token] = $this->confirmedConsent();

        $child = User::where('is_minor', true)->first();
        $oldHash = $child->login_code_hash;

        $response = $this->postJson("/api/consent/manage/{$token}/regenerate", ['child_id' => $child->id]);

        $response->assertOk();
        $newCode = $response->json('code');

        $this->assertNotNull($newCode);
        $this->assertSame(StudentCodes::hash($newCode), $child->fresh()->login_code_hash);
        $this->assertNotSame($oldHash, $child->fresh()->login_code_hash);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'code_regenerated']);
    }

    public function test_regenerate_refuses_a_child_of_another_guardian(): void
    {
        [, $tokenA] = $this->confirmedConsent('a@example.com');
        $this->confirmedConsent('b@example.com');

        $foreignChild = User::where('is_minor', true)->latest('id')->first();

        $this->postJson("/api/consent/manage/{$tokenA}/regenerate", ['child_id' => $foreignChild->id])
            ->assertStatus(404);
    }

    public function test_revoke_blocks_login_immediately_and_schedules_the_purge(): void
    {
        [$consent, $token] = $this->confirmedConsent();

        $child = User::where('is_minor', true)->first();
        $bearerToken = $child->createToken('test')->plainTextToken;

        $this->postJson("/api/consent/manage/{$token}/revoke")->assertOk();

        $consent = $consent->fresh();
        $this->assertSame('revoked', $consent->status);
        $this->assertNotNull($consent->revoked_at);
        $this->assertTrue($consent->purge_after->greaterThan(now()->addDays(29)));

        Mail::assertSent(ConsentRevokedMail::class);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'revoked']);

        // The test's name claims login is blocked immediately — this is
        // what actually proves it: the child's live bearer token, issued
        // before the revocation, must stop authenticating the moment the
        // revocation completes, not merely have its DB columns changed.
        $this->withHeader('Authorization', "Bearer {$bearerToken}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * C1 belt-and-braces regression test. ConsentService::confirm()'s
     * supersede fix should prevent a guardian from ever holding two
     * simultaneously CONFIRMED consents going forward, but revoke() does
     * not rely on that alone — it revokes every CONFIRMED row for the
     * guardian, not just the one the manage token happened to resolve to.
     * This forces the pre-fix state directly (bypassing ConsentService, the
     * same way MinorLoginTest's C1 test does) so a legacy duplicate cannot
     * survive a revocation through this path either.
     */
    public function test_revoke_revokes_every_confirmed_consent_for_the_guardian(): void
    {
        [$consent, $token] = $this->confirmedConsent();

        $stale = Consent::startFor($consent->guardian, 'cs', 'CZ');
        $stale->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();

        $this->postJson("/api/consent/manage/{$token}/revoke")->assertOk();

        $this->assertSame('revoked', $consent->fresh()->status);
        $this->assertSame(
            'revoked',
            $stale->fresh()->status,
            'a second confirmed consent for the same guardian must be revoked too, not left live'
        );
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'revoked', 'consent_id' => $stale->id]);
    }

    public function test_manage_token_dies_with_the_revocation(): void
    {
        [, $token] = $this->confirmedConsent();

        $this->postJson("/api/consent/manage/{$token}/revoke")->assertOk();

        $this->getJson("/api/consent/manage/{$token}")->assertStatus(404);
    }

    /**
     * Regression guard for the isRevoked() check in resolve(). revoke()
     * already invalidates every consent_codes row belonging to the consent,
     * so test_manage_token_dies_with_the_revocation above passes on that
     * alone and would keep passing even if isRevoked() were deleted — it
     * gives isRevoked() zero real coverage. This test flips consent.status
     * to revoked directly, without going through revoke(), so the token's
     * consent_codes row is still live and only the isRevoked() guard can
     * reject it: defence in depth for any future path that changes consent
     * status without also invalidating codes.
     */
    public function test_revoked_status_without_invalidated_codes_is_still_rejected(): void
    {
        [$consent, $token] = $this->confirmedConsent();

        $consent->fresh()->forceFill(['status' => Consent::STATUS_REVOKED])->save();

        $this->getJson("/api/consent/manage/{$token}")->assertStatus(404);
    }

    public function test_regenerate_kills_the_childs_live_session(): void
    {
        [, $token] = $this->confirmedConsent();

        $child = User::where('is_minor', true)->first();
        $bearerToken = $child->createToken('test')->plainTextToken;

        $this->postJson("/api/consent/manage/{$token}/regenerate", ['child_id' => $child->id])
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$bearerToken}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * Without this, sendManageLink() could be reduced to a no-op and the
     * suite would stay green — nothing else exercises it directly.
     */
    public function test_send_manage_link_sends_mail_issues_token_and_records_link_sent(): void
    {
        [, $oldToken] = $this->confirmedConsent();

        $this->postJson('/api/consent/manage/request-link', ['email' => 'rodic@example.com'])
            ->assertOk();

        $newToken = null;
        Mail::assertSent(ConsentManageLinkMail::class, function ($mail) use (&$newToken) {
            $newToken = basename($mail->manageUrl);

            return true;
        });

        $this->assertNotNull($newToken);
        $this->assertNotSame($oldToken, $newToken);

        // Issuing the new token burns the old one (ConsentCode::issue()
        // invalidates the live predecessor for the same purpose).
        $this->getJson("/api/consent/manage/{$oldToken}")->assertStatus(404);
        $this->getJson("/api/consent/manage/{$newToken}")->assertOk();

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'link_sent']);
    }

    /**
     * The compensating control decision 11 promises for the token living in
     * the URL path — otherwise this is folklore, not a tested behaviour.
     */
    public function test_resolving_the_token_records_link_opened(): void
    {
        [, $token] = $this->confirmedConsent();

        $this->getJson("/api/consent/manage/{$token}")->assertOk();

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'link_opened']);
    }

    public function test_request_link_answers_identically_for_unknown_email(): void
    {
        $this->confirmedConsent('known@example.com');

        $known = $this->postJson('/api/consent/manage/request-link', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/consent/manage/request-link', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    /**
     * C1 regression test. Mail::fake() never throws, so without a real
     * failure the try/catch in ConsentService::revoke() was dead code.
     * Mail::shouldReceive(...)->andThrow(...) forces the send to fail so
     * that path actually runs, and proves the revocation itself — the
     * status flip, the token/code invalidation, the audit row — survives a
     * dead mailer instead of vanishing behind an uncaught exception.
     */
    public function test_revoke_survives_a_mail_outage(): void
    {
        Log::spy();

        [$consent, $token] = $this->confirmedConsent();

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $response = $this->postJson("/api/consent/manage/{$token}/revoke");

        $response->assertOk();
        $response->assertJson(['revoked' => true]);

        $consent = $consent->fresh();
        $this->assertSame('revoked', $consent->status);
        $this->assertNotNull($consent->revoked_at);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'revoked']);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'mail_failed']);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'consent.revoke.mail_failed'
                    && ! isset($context['email'])
                    && ! isset($context['exception'])
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && ! str_contains((string) json_encode($context), 'rodic@example.com');
            });
    }

    /**
     * C2 regression test. Without sendManageLink()'s try/catch and
     * requestLink()'s catching wrapper, a known guardian's mail failure
     * propagated as an uncaught exception (500) while an unknown address
     * always answered 200 — a cleaner email-enumeration oracle than any
     * timing measurement. Mail::fake() never throws, so this needs a forced
     * failure to actually exercise that path.
     */
    public function test_request_link_stays_constant_when_the_mail_fails(): void
    {
        Log::spy();

        $this->confirmedConsent('rodic@example.com');

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $response = $this->postJson('/api/consent/manage/request-link', ['email' => 'rodic@example.com']);

        $response->assertOk();
        $response->assertJson(['sent' => true]);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'link_sent']);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'mail_failed']);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'consent.manage.request_link.failed'
                    && ! isset($context['email'])
                    && ! isset($context['exception'])
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && ! str_contains((string) json_encode($context), 'rodic@example.com');
            });
    }

    /**
     * Regression test for the follow-up fix to sendManageLink(): the old
     * manage token must stay live when the resend mail fails, not get
     * invalidated up front. ConsentCode::issue() is called with
     * invalidatePredecessors: false and the old token is only invalidated
     * after Mail::send() returns successfully — a failed send here means
     * that invalidation line never runs, so the guardian's previous link
     * must still resolve.
     */
    public function test_the_old_manage_link_survives_a_failed_resend(): void
    {
        [, $oldToken] = $this->confirmedConsent();

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $this->postJson('/api/consent/manage/request-link', ['email' => 'rodic@example.com'])
            ->assertOk();

        $this->getJson("/api/consent/manage/{$oldToken}")->assertOk();
    }
}
