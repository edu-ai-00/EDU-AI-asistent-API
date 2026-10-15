<?php

namespace Tests\Feature;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentRequestMail;
use App\Models\Classroom;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\ConsentCode;
use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConsentConfirmTest extends TestCase
{
    use RefreshDatabase;

    /** Run the request step and return [consent, plaintext code]. */
    private function requestConsent(string $email = 'rodic@example.com'): array
    {
        Mail::fake();

        $this->postJson('/api/consent/request', [
            'email' => $email,
            'locale' => 'cs',
            'country' => 'CZ',
        ])->assertOk();

        $code = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return [Consent::where('status', 'pending')->latest('id')->first(), $code];
    }

    /**
     * Open a second pending consent for a guardian who already confirmed one,
     * and return [consent, plaintext code].
     *
     * Not a second POST to /api/consent/request: since BR-2JW929 that
     * endpoint short-circuits for an already-confirmed guardian (overview
     * mail, no new consent), so the only way left to reach this state is two
     * requests racing before either one confirms. Narrower window, same
     * state — and the guards the tests below cover (top-up instead of
     * duplicate provisioning, superseding the earlier CONFIRMED row) are
     * exactly what has to hold when it happens. Driving it through the models
     * keeps those guards under test instead of deleting the coverage along
     * with the public path.
     */
    private function raceASecondPendingConsent(Consent $confirmed): array
    {
        $consent = Consent::startFor($confirmed->guardian, 'cs', 'CZ');
        [$code] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        return [$consent, $code];
    }

    public function test_confirm_creates_self_service_class_with_three_minor_accounts(): void
    {
        [$consent, $code] = $this->requestConsent();

        $response = $this->postJson('/api/consent/confirm', [
            'id' => $consent->public_id,
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJson(['confirmed' => true, 'mail_sent' => true]);

        $this->assertSame('confirmed', $consent->fresh()->status);
        $this->assertNotNull($consent->fresh()->confirmed_at);

        $classroom = Classroom::where('type', 'self_service')->first();
        $this->assertNotNull($classroom);
        $this->assertNotSame('rodic@example.com', $classroom->name, 'the classroom name must not copy the guardian email');
        $this->assertSame($consent->guardian_id, $classroom->guardian_id);

        $children = User::where('is_minor', true)->get();
        $this->assertCount(config('consent.children_per_guardian'), $children);

        foreach ($children as $child) {
            $this->assertNull($child->email, 'a child account must hold no email');
            $this->assertNull($child->login_code, 'a child code must never be stored in plaintext');
            $this->assertNotNull($child->login_code_hash);
            $this->assertSame([$classroom->id], $child->classrooms()->pluck('classrooms.id')->all());
            $this->assertSame($consent->guardian_id, $child->guardian_id);
        }
    }

    public function test_confirm_emails_the_codes_and_they_actually_work(): void
    {
        [$consent, $code] = $this->requestConsent();

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();

        $children = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$children) {
            $children = $mail->children;

            return true;
        });

        $this->assertCount(config('consent.children_per_guardian'), $children);

        foreach ($children as $child) {
            $this->assertDatabaseHas('users', [
                'name' => $child['name'],
                'login_code_hash' => StudentCodes::hash($child['code']),
            ]);
        }
    }

    public function test_wrong_code_is_rejected_and_audited(): void
    {
        [$consent] = $this->requestConsent();

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => 'ZZZZZZ'])
            ->assertStatus(422);

        $this->assertSame('pending', $consent->fresh()->status);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'failed_attempt']);
        $this->assertSame(0, User::where('is_minor', true)->count());
    }

    public function test_code_stops_working_after_five_failed_attempts(): void
    {
        // This test exercises the per-code attempt budget on ConsentCode, a
        // separate invariant from the 'consent-confirm' IP throttle
        // (Limit::perMinute(5), registered in AppServiceProvider). Six
        // requests land here from the same test IP within the same test, and
        // without this the IP throttle trips on the sixth request (429)
        // before the attempt-budget assertion below is ever reached.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        [$consent, $code] = $this->requestConsent();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => 'ZZZZZZ'])
                ->assertStatus(422);
        }

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])
            ->assertStatus(422);

        $this->assertSame('pending', $consent->fresh()->status);
    }

    public function test_confirming_twice_does_not_create_a_second_batch_of_children(): void
    {
        [$consent, $code] = $this->requestConsent();

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();
        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertStatus(422);

        $this->assertSame(config('consent.children_per_guardian'), User::where('is_minor', true)->count());
    }

    public function test_unknown_consent_id_is_rejected(): void
    {
        $this->postJson('/api/consent/confirm', [
            'id' => '00000000-0000-4000-8000-000000000000',
            'code' => 'AB12CD',
        ])->assertStatus(422);
    }

    /**
     * Regression test for the second guard in ConsentService::confirm(): the
     * `status !== PENDING` check. ConsentCode::redeem()'s single-use claim is
     * the primary guard against double-provisioning, but it only protects
     * against *reusing the same spent code* — it has nothing to say about a
     * fresh, still-valid confirm code existing against a consent that is no
     * longer pending. That situation should never arise through the normal
     * request/confirm flow (nothing reissues a 'confirm' code once a consent
     * leaves pending), but the status check is defense-in-depth against it,
     * and mutation testing showed the other 5 tests in this file pass even
     * with that check deleted — this test is the one that must fail without
     * it.
     */
    public function test_confirm_refuses_a_fresh_valid_code_once_consent_is_no_longer_pending(): void
    {
        [$consent] = $this->requestConsent();

        $consent->forceFill(['status' => Consent::STATUS_REVOKED, 'revoked_at' => now()])->save();

        [$freshCode] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $freshCode])
            ->assertStatus(422);

        $this->assertSame('revoked', $consent->fresh()->status);
        $this->assertSame(0, User::where('is_minor', true)->count());
        $this->assertNull(Classroom::where('type', 'self_service')->first());
    }

    /**
     * C1 regression test. provisionChildren() used to loop
     * children_per_guardian times unconditionally on every confirm; a
     * guardian who completed the whole flow twice (lost the first email, new
     * phone, or followed the mail-failure recovery path) got a second batch
     * of three because requestConsent() only supersedes still-*pending*
     * consents — a confirmed one is left alone, so the second request opens
     * a genuinely fresh, genuinely pending consent that sails through both
     * guards in confirm(). This drives two full request → confirm cycles for
     * one guardian and asserts the guardian still has exactly
     * config('consent.children_per_guardian') minors and one classroom
     * afterwards — and, since the second cycle can only reach the existing
     * classroom via firstOrCreate()'s find branch, this is also the only
     * place that branch is exercised.
     */
    public function test_two_full_cycles_for_the_same_guardian_top_up_instead_of_duplicating(): void
    {
        [$firstConsent, $firstCode] = $this->requestConsent();

        $this->postJson('/api/consent/confirm', ['id' => $firstConsent->public_id, 'code' => $firstCode])
            ->assertOk();

        $firstClassroom = Classroom::where('type', 'self_service')->firstOrFail();
        $this->assertSame(config('consent.children_per_guardian'), User::where('is_minor', true)->count());

        [$secondConsent, $secondCode] = $this->raceASecondPendingConsent($firstConsent->fresh());
        $this->assertNotSame($firstConsent->id, $secondConsent->id, 'the second cycle must run on a fresh consent');

        $response = $this->postJson('/api/consent/confirm', ['id' => $secondConsent->public_id, 'code' => $secondCode]);
        $response->assertOk();
        $response->assertJson(['confirmed' => true]);
        // The top-up created zero new children, but the response must report
        // the guardian's total (config('consent.children_per_guardian')),
        // not that delta — telling a parent who already has three children
        // that they now have "0 children" would be actively wrong.
        $response->assertJson(['children' => config('consent.children_per_guardian')]);

        $this->assertSame('confirmed', $secondConsent->fresh()->status);
        $this->assertSame(
            config('consent.children_per_guardian'),
            User::where('is_minor', true)->count(),
            'a second confirmation for the same guardian must top up, not duplicate'
        );
        $this->assertSame(1, Classroom::where('type', 'self_service')->count(), 'no second classroom');
        $this->assertSame(
            $firstClassroom->id,
            Classroom::where('type', 'self_service')->firstOrFail()->id,
            'the second cycle must reuse the existing classroom via firstOrCreate()\'s find branch'
        );
    }

    /**
     * C2 regression test. The child-code collision loop used to check only
     * login_code_hash, which is always NULL for a school student — their
     * code lives in the plaintext login_code column. Task 9's code lookup
     * tries the plaintext column first, so a generated child code equal to
     * an existing school student's code would let the child log in as that
     * student and leave the child's own account unreachable.
     *
     * A real random collision in the ~32^6 code space is far too rare to hit
     * on demand, so this forces one deterministically: ConsentService's
     * generateCandidateCode() seam is partial-mocked to hand back the school
     * student's own plaintext code on the very first call — the rest are
     * distinct, otherwise-unused codes, one per child actually provisioned.
     * With the bug (login_code_hash-only check), that first candidate is
     * accepted outright — nothing already has that *hash* — and a child is
     * created whose login_code_hash equals the school student's plaintext
     * code. With the fix, orWhere('login_code', ...) rejects that first
     * candidate and the loop retries with the next one in the sequence.
     */
    public function test_child_code_never_collides_with_an_existing_plaintext_school_code(): void
    {
        $seededCode = 'AB12CD';
        User::create([
            'name' => 'Školák',
            'login_code' => $seededCode,
        ]);

        [$consent, $code] = $this->requestConsent();

        // One distinct fallback code per child, so a fixed (non-random)
        // sequence never makes two children collide with each other.
        $target = (int) config('consent.children_per_guardian');
        $fallbackCodes = array_map(fn ($i) => sprintf('ZZZ%03d', $i), range(1, $target));

        $this->partialMock(\App\Services\ConsentService::class, function ($mock) use ($seededCode, $fallbackCodes) {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('generateCandidateCode')
                ->andReturn($seededCode, ...$fallbackCodes);
        });

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();

        $seededHash = StudentCodes::hash($seededCode);
        $collision = User::where('is_minor', true)->where('login_code_hash', $seededHash)->exists();

        $this->assertFalse($collision, 'a generated child code must never equal an existing plaintext school code');
        $this->assertSame($target, User::where('is_minor', true)->count(), 'all children must still get provisioned despite the retry');
    }

    /**
     * C1 regression test. A guardian must have at most one governing
     * consent — otherwise revoking one CONFIRMED row can leave a sibling
     * CONFIRMED row still authorising logins (BR-8FDF37 C1). Without
     * supersedeConfirmedConsents() in confirm(), a second full
     * request→confirm cycle for the same guardian (the same scenario
     * test_two_full_cycles_for_the_same_guardian_top_up_instead_of_duplicating
     * drives) would leave the FIRST consent sitting at 'confirmed' forever
     * alongside the second — this is the test that actually checks its
     * status.
     */
    public function test_second_confirmation_supersedes_the_first_confirmed_consent(): void
    {
        [$firstConsent, $firstCode] = $this->requestConsent();
        $this->postJson('/api/consent/confirm', ['id' => $firstConsent->public_id, 'code' => $firstCode])
            ->assertOk();

        [$secondConsent, $secondCode] = $this->raceASecondPendingConsent($firstConsent->fresh());
        $this->postJson('/api/consent/confirm', ['id' => $secondConsent->public_id, 'code' => $secondCode])
            ->assertOk();

        $this->assertSame('expired', $firstConsent->fresh()->status);
        $this->assertSame('confirmed', $secondConsent->fresh()->status);

        $superseded = ConsentAuditLog::where('event', 'superseded')
            ->where('consent_id', $firstConsent->id)
            ->first();
        $this->assertNotNull($superseded, 'the confirmed -> expired transition must be audited');
        $this->assertSame($secondConsent->id, $superseded->meta['superseded_by_consent_id'] ?? null);
    }

    public function test_confirm_issues_a_manage_token_with_a_well_formed_url(): void
    {
        [$consent, $code] = $this->requestConsent();

        $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code])->assertOk();

        $this->assertDatabaseHas('consent_codes', [
            'consent_id' => $consent->id,
            'purpose' => 'manage',
        ]);

        $manageUrl = null;
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$manageUrl) {
            $manageUrl = $mail->manageUrl;

            return true;
        });

        $this->assertNotNull($manageUrl);
        $this->assertStringStartsWith(rtrim((string) config('consent.web_base_url'), '/').'/consent/manage/', $manageUrl);
        $token = substr($manageUrl, strrpos($manageUrl, '/') + 1);
        $this->assertSame(64, strlen($token), 'the token in the URL must be the raw hex manage token');
    }

    /**
     * I4 regression test. Mail::fake() never throws, so without a real
     * failure the entire catch block in ConsentService::confirm() — the
     * deliberate, reviewed non-obvious behaviour that keeps the
     * confirmation successful even when the mailer is down — was dead code
     * in this suite. Mail::shouldReceive(...)->andThrow(...) forces the send
     * to fail so that path actually runs.
     */
    public function test_confirm_still_succeeds_when_the_granted_mail_fails_to_send(): void
    {
        Log::spy();

        [$consent, $code] = $this->requestConsent();

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused while delivering to rodic@example.com'));

        $response = $this->postJson('/api/consent/confirm', ['id' => $consent->public_id, 'code' => $code]);

        $response->assertOk();
        $response->assertJson(['confirmed' => true, 'mail_sent' => false]);

        $this->assertSame('confirmed', $consent->fresh()->status);
        $this->assertSame(config('consent.children_per_guardian'), User::where('is_minor', true)->count());
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'mail_failed']);
        $this->assertDatabaseHas('consent_audit_logs', ['event' => 'confirmed']);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'consent.confirm.mail_failed'
                    && ! isset($context['email'])
                    && ! isset($context['exception'])
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && ! str_contains((string) json_encode($context), 'rodic@example.com');
            });
    }
}
