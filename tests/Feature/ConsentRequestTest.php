<?php

namespace Tests\Feature;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentRequestMail;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\ConsentCode;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConsentRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_creates_guardian_pending_consent_and_sends_mail(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/consent/request', [
            'email' => 'Rodic@Example.com',
            'locale' => 'cs',
            'country' => 'CZ',
        ]);

        $response->assertOk();
        $response->assertJson(['sent' => true]);

        $guardian = Guardian::first();
        $this->assertSame('rodic@example.com', $guardian->email, 'email must be normalised');
        $consent = Consent::first();
        $this->assertSame('pending', $consent->status);

        Mail::assertSent(ConsentRequestMail::class, fn ($mail) => $mail->hasTo('rodic@example.com'));

        $audit = ConsentAuditLog::where('event', 'requested')->first();
        $this->assertNotNull($audit);
        $this->assertSame($consent->id, $audit->consent_id, 'audit row must link to the consent it describes');
        $this->assertSame($guardian->id, $audit->guardian_id, 'audit row must link to the guardian it describes');
        $this->assertSame(config('consent.text_version'), $audit->meta['text_version'] ?? null);
        $this->assertSame('CZ', $audit->meta['country'] ?? null);
    }

    public function test_repeated_request_reuses_the_same_guardian(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);
        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);

        $this->assertSame(1, Guardian::count(), 'a second request must not duplicate the guardian');
        $this->assertSame(2, Consent::count());
    }

    public function test_response_is_identical_for_known_and_unknown_email(): void
    {
        Mail::fake();

        $first = $this->postJson('/api/consent/request', ['email' => 'a@example.com', 'locale' => 'cs', 'country' => 'CZ']);
        $this->postJson('/api/consent/request', ['email' => 'a@example.com', 'locale' => 'cs', 'country' => 'CZ']);
        $second = $this->postJson('/api/consent/request', ['email' => 'b@example.com', 'locale' => 'cs', 'country' => 'CZ']);

        $this->assertSame($first->json(), $second->json(), 'no email enumeration through the response body');
    }

    public function test_no_plaintext_code_is_persisted(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);

        $sent = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$sent) {
            $sent = $mail->code;

            return true;
        });

        $this->assertNotNull($sent);
        $this->assertDatabaseMissing('consent_codes', ['code_hash' => $sent]);

        $leakedIntoAuditMeta = ConsentAuditLog::query()
            ->get()
            ->contains(fn (ConsentAuditLog $log) => str_contains((string) json_encode($log->meta), $sent));
        $this->assertFalse($leakedIntoAuditMeta, 'the plaintext code must never appear in an audit log meta blob either');
    }

    /**
     * BR-FBD2E9: the parent clicked the link, then had to go back to the
     * email and retype the code shown right above it. The link now carries
     * the code so the confirm page can prefill it.
     */
    public function test_confirm_link_carries_the_emailed_code_so_the_parent_never_retypes_it(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);

        $consent = Consent::firstOrFail();

        Mail::assertSent(ConsentRequestMail::class, function ($mail) use ($consent) {
            $this->assertStringContainsString('id='.$consent->public_id, $mail->confirmUrl);
            $this->assertStringContainsString('code='.$mail->code, $mail->confirmUrl);

            return true;
        });
    }

    public function test_repeated_request_expires_previous_pending_consent_and_invalidates_its_code(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);
        $firstConsent = Consent::first();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ']);

        $sentCodes = [];
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$sentCodes) {
            $sentCodes[] = $mail->code;

            return true;
        });
        $this->assertCount(2, $sentCodes, 'two requests must send two mails');

        $this->assertSame(2, Consent::count());
        $this->assertSame(1, Consent::where('status', 'pending')->count(), 'only the newest consent may stay pending');
        $this->assertSame('expired', $firstConsent->fresh()->status);

        $secondConsent = Consent::where('status', 'pending')->firstOrFail();

        $this->assertNull(
            ConsentCode::redeem($firstConsent->fresh(), $sentCodes[0], ConsentCode::PURPOSE_CONFIRM),
            'the superseded code must no longer redeem'
        );
        $this->assertNotNull(
            ConsentCode::redeem($secondConsent, $sentCodes[1], ConsentCode::PURPOSE_CONFIRM),
            'the newest code must still redeem'
        );

        $superseded = ConsentAuditLog::where('event', 'superseded')->first();
        $this->assertNotNull($superseded, 'the pending -> expired transition must be audited too');
        $this->assertSame($firstConsent->id, $superseded->consent_id, 'the superseded row belongs to the old consent');
        $this->assertSame(
            $secondConsent->id,
            $superseded->meta['superseded_by_consent_id'] ?? null,
            'meta must reconstruct which consent replaced it'
        );
    }

    /**
     * Drive a full request → confirm cycle so the guardian ends up with a
     * CONFIRMED consent and its provisioned children, then hand the caller
     * back the consent. The shortcut path below is only reachable from this
     * state.
     */
    private function confirmedGuardian(string $email = 'rodic@example.com'): Consent
    {
        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => $email, 'locale' => 'cs', 'country' => 'CZ'])->assertOk();

        $code = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $consent = Consent::where('status', 'pending')->latest('id')->firstOrFail();

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();

        return $consent->fresh();
    }

    /**
     * BR-2JW929: the second child in a family reaches the same endpoint with
     * the same parent email. That parent already consented and already has
     * every account this feature provisions, so asking them to consent again
     * is noise — and confirming it would only produce a "codes" mail with no
     * codes in it. They get the overview mail instead, and no new consent row
     * or confirm code is created.
     */
    public function test_repeat_request_for_an_already_confirmed_guardian_sends_the_overview_instead_of_a_new_ask(): void
    {
        $consent = $this->confirmedGuardian();

        $consentsBefore = Consent::count();
        $confirmCodesBefore = ConsentCode::where('purpose', ConsentCode::PURPOSE_CONFIRM)->count();

        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk()
            ->assertJson(['sent' => true]);

        Mail::assertNotSent(ConsentRequestMail::class);

        $childNames = User::where('guardian_id', $consent->guardian_id)
            ->where('is_minor', true)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use ($childNames) {
            return $mail->hasTo('rodic@example.com')
                && $mail->children === []
                && $mail->existingChildren === $childNames;
        });

        $this->assertSame($consentsBefore, Consent::count(), 'no second consent row may be opened');
        $this->assertSame(
            $confirmCodesBefore,
            ConsentCode::where('purpose', ConsentCode::PURPOSE_CONFIRM)->count(),
            'no new confirm code may be issued for a guardian who already consented'
        );
        $this->assertSame('confirmed', $consent->fresh()->status, 'the governing consent must be left alone');
        $this->assertSame(
            config('consent.children_per_guardian'),
            User::where('is_minor', true)->count(),
            'the shortcut must not provision another batch of children'
        );

        $this->assertDatabaseHas('consent_audit_logs', [
            'event' => 'overview_sent',
            'consent_id' => $consent->id,
        ]);
    }

    /** The manage link in that overview mail has to actually work. */
    public function test_the_overview_mail_carries_a_live_manage_link(): void
    {
        $this->confirmedGuardian();

        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk();

        $manageUrl = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$manageUrl) {
            $manageUrl = $mail->manageUrl;

            return true;
        });

        $token = basename((string) $manageUrl);

        $this->getJson('/api/consent/manage/'.$token)
            ->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonCount(config('consent.children_per_guardian'), 'children');
    }

    /**
     * Consent that was withdrawn has to be given again — the shortcut keys
     * off the *governing* consent's status, so a revoked guardian falls
     * through to the normal ask-again path.
     */
    public function test_a_revoked_guardian_is_asked_to_consent_again(): void
    {
        $consent = $this->confirmedGuardian();

        $manageUrl = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$manageUrl) {
            $manageUrl = $mail->manageUrl;

            return true;
        });

        $this->postJson('/api/consent/manage/'.basename((string) $manageUrl).'/revoke')->assertOk();
        $this->assertSame('revoked', $consent->fresh()->status);

        Mail::fake();

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk();

        Mail::assertSent(ConsentRequestMail::class);
        Mail::assertNotSent(ConsentGrantedMail::class);
        $this->assertSame(1, Consent::where('status', 'pending')->count(), 'a revoked guardian gets a fresh pending consent');
    }

    /**
     * Same contract as the ask-again path: a mail outage must not change the
     * response (that would leak that this address is known), must be audited,
     * and must reach the operational log without carrying the address into it.
     */
    public function test_overview_mail_failure_keeps_the_response_constant_and_is_audited(): void
    {
        $this->confirmedGuardian();

        Log::spy();
        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk()
            ->assertJson(['sent' => true]);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'overview_sent']);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'mail_failed']);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'consent.request.failed'
                    && ! str_contains((string) json_encode($context), 'rodic@example.com');
            });
    }

    /**
     * A failed overview send must leave the guardian's previous manage link
     * working — same reasoning as sendManageLink(): stripping their only live
     * token during an outage leaves them locked out of their own accounts.
     */
    public function test_a_failed_overview_send_leaves_the_previous_manage_link_alive(): void
    {
        $this->confirmedGuardian();

        $manageUrl = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$manageUrl) {
            $manageUrl = $mail->manageUrl;

            return true;
        });
        $token = basename((string) $manageUrl);

        Mail::shouldReceive('to->send')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertOk();

        $this->getJson('/api/consent/manage/'.$token)->assertOk();
    }

    public function test_requested_audit_row_survives_a_mail_failure(): void
    {
        Log::spy();
        // Real mailer/SMTP failures routinely embed the recipient address in
        // their message text — assert that address never reaches the log.
        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $response = $this->postJson('/api/consent/request', [
            'email' => 'rodic@example.com',
            'locale' => 'cs',
            'country' => 'CZ',
        ]);

        // The response contract is unchanged by a mail outage.
        $response->assertOk();
        $response->assertJson(['sent' => true]);

        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'requested']);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'mail_failed']);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'consent.request.failed'
                    && ! isset($context['email'])
                    && ! isset($context['exception'])
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && ! str_contains((string) json_encode($context), 'rodic@example.com');
            });
    }

    public function test_locale_en_without_country_fails_conservative_to_the_highest_threshold(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', [
            'email' => 'rodic@example.com',
            'locale' => 'en',
        ])->assertOk();

        Mail::assertSent(ConsentRequestMail::class, function ($mail) {
            return $mail->ageThreshold === 16;
        });
    }

    public function test_fourth_request_for_the_same_email_within_an_hour_is_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
                ->assertOk();
        }

        $this->postJson('/api/consent/request', ['email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ'])
            ->assertStatus(429);
    }

    public function test_config_endpoint_exposes_age_thresholds(): void
    {
        $response = $this->getJson('/api/consent/config');

        $response->assertOk();
        $response->assertJsonPath('age_thresholds.CZ', 15);
        $response->assertJsonPath('age_thresholds.SK', 16);
        $response->assertJsonPath('default_age_threshold', 15);
    }
}
