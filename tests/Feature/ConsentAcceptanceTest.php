<?php

namespace Tests\Feature;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentRequestMail;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\ConsentCode;
use App\Models\DebugReport;
use App\Models\Guardian;
use App\Models\PracticeCard;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserProgress;
use App\Models\UserStats;
use App\Models\VerificationCode;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The acceptance criteria of BR-8FDF37, expressed as tests.
 *
 * These are deliberately written against the *promise*, not the mechanism
 * that currently keeps it: they drive the public HTTP surface and the
 * scheduled command, and they assert over the whole database and the whole
 * log stream rather than over the one column or the one code path today's
 * implementation happens to use. A future refactor that keeps every existing
 * unit test green while breaking a promise by some other route must still
 * fail here.
 *
 * Criteria, verbatim from the report:
 *  1. Dítě s locale SK vidí hranici 16, v CZ 15.
 *  2. Souhlas nelze potvrdit z aplikace dítěte — jen z webové stránky,
 *     odkazu z e-mailu.
 *  3. Po odvolání souhlasu se dítě nepřihlásí a do 30 dní neexistují jeho
 *     data (ověřit jobem + testem).
 *  4. V audit logu existují záznamy: rodič potvrdil souhlas, obdržel kódy,
 *     odvolal souhlas.
 *  5. V DB neexistuje žádný plaintext kód; v lozích žádný kód ani e-mail
 *     dítěte (dítě žádný nemá, app neumožní s emailem propojit).
 *  6. Opakovaná žádost o souhlas na stejný e-mail nevytvoří duplicitní
 *     guardian.
 *  7. Rate limity ověřené integračními testy.
 */
class ConsentAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drive the whole parent-facing flow exactly as a parent would: request,
     * read the emailed code, confirm from the emailed id.
     *
     * @return array{
     *     consent: Consent,
     *     confirmCode: string,
     *     codes: array<int, array{name: string, code: string}>,
     *     manageToken: string,
     * }
     */
    private function runFullFlow(string $email = 'rodic@example.com', string $country = 'CZ'): array
    {
        Mail::fake();

        $this->postJson('/api/consent/request', [
            'email' => $email,
            'locale' => $country === 'SK' ? 'sk' : 'cs',
            'country' => $country,
        ])->assertOk();

        $confirmCode = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$confirmCode) {
            $confirmCode = $mail->code;

            return true;
        });

        $consent = Consent::latest('id')->firstOrFail();

        $response = $this->postJson('/api/consent/confirm', [
            'id' => $consent->public_id,
            'code' => $confirmCode,
        ]);

        $response->assertOk();
        // confirm() reports the guardian's TOTAL minor count, not the number
        // created by this call — asserted here so the helper itself notices a
        // regression in that contract.
        $response->assertJson([
            'confirmed' => true,
            'children' => (int) config('consent.children_per_guardian'),
            'mail_sent' => true,
        ]);

        $codes = [];
        $manageToken = '';
        Mail::assertSent(ConsentGrantedMail::class, function ($mail) use (&$codes, &$manageToken) {
            $codes = $mail->children;
            $manageToken = basename($mail->manageUrl);

            return true;
        });

        $this->assertNotSame('', $manageToken);
        $this->assertCount((int) config('consent.children_per_guardian'), $codes);

        return [
            'consent' => $consent->fresh(),
            'confirmCode' => (string) $confirmCode,
            'codes' => $codes,
            'manageToken' => $manageToken,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 1 — SK sees 16, CZ sees 15
    //
    // Only partly a server promise. The server owns two things: the
    // thresholds it publishes at /api/consent/config (the app overrides its
    // bundled fallback with them) and the number it prints in the legal
    // sentence of the request email. Both are asserted below.
    //
    // What the server CANNOT prove, and what these tests deliberately do not
    // pretend to: that a child whose device locale is SK actually SEES 16 on
    // the age gate. That is Flutter — AgeThreshold.detectCountry() /
    // forCountry() in eduai-app/lib/core/consent/age_threshold.dart and the
    // gate widget that renders it — and it is covered by the app's own test
    // suite, not by anything reachable from here.
    // ─────────────────────────────────────────────────────────────────────

    public function test_c1_server_publishes_fifteen_for_cz_and_sixteen_for_sk(): void
    {
        $response = $this->getJson('/api/consent/config');

        $response->assertOk();
        $response->assertJsonPath('age_thresholds.CZ', 15);
        $response->assertJsonPath('age_thresholds.SK', 16);
        $response->assertJsonPath('default_age_threshold', 15);
    }

    /**
     * The number the parent actually reads. A config endpoint the client
     * ignores would still pass the test above; the legal sentence in the
     * email is the other place the threshold is stated, and it is rendered
     * here rather than merely read off the mailable's property.
     */
    public function test_c1_request_email_states_the_country_specific_threshold(): void
    {
        Mail::fake();

        $this->postJson('/api/consent/request', [
            'email' => 'cz@example.com', 'locale' => 'cs', 'country' => 'CZ',
        ])->assertOk();

        $this->postJson('/api/consent/request', [
            'email' => 'sk@example.com', 'locale' => 'sk', 'country' => 'SK',
        ])->assertOk();

        // A Slovak-locale parent who never picked a country must still be
        // told 16 — the locale resolves the country when none was sent.
        $this->postJson('/api/consent/request', [
            'email' => 'sk-nocountry@example.com', 'locale' => 'sk',
        ])->assertOk();

        $rendered = [];
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$rendered) {
            $rendered[$mail->to[0]['address']] = [
                'threshold' => $mail->ageThreshold,
                'body' => $mail->render(),
            ];

            return true;
        });

        $this->assertSame(15, $rendered['cz@example.com']['threshold']);
        $this->assertStringContainsString('mladší 15 let', $rendered['cz@example.com']['body']);

        $this->assertSame(16, $rendered['sk@example.com']['threshold']);
        $this->assertStringContainsString('mladšie ako 16 rokov', $rendered['sk@example.com']['body']);

        $this->assertSame(16, $rendered['sk-nocountry@example.com']['threshold']);
        $this->assertStringContainsString('mladšie ako 16 rokov', $rendered['sk-nocountry@example.com']['body']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 2 — consent can only be confirmed from the emailed link
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The promise has two halves, and both are asserted here:
     *
     *  - a child's session is NOT SUFFICIENT: holding a live minor token,
     *    and even knowing the consent's public id, confirms nothing without
     *    the code that only reached the parent's inbox;
     *  - the emailed code IS SUFFICIENT on its own: the same consent
     *    confirms from a completely unauthenticated request, which is what
     *    "jen z webové stránky, odkazu z e-mailu" actually means — the web
     *    page has no session either.
     *
     * The plan's draft only fired a guessed UUID at the endpoint, which
     * would keep passing even if someone added an explicit "confirm my
     * guardian's pending consent from my app session" shortcut. This drives
     * a REAL pending consent belonging to the logged-in child's own guardian
     * — the exact case such a shortcut would serve.
     */
    public function test_c2_a_child_session_can_neither_read_nor_confirm_a_consent(): void
    {
        $flow = $this->runFullFlow();

        $login = $this->postJson('/api/resolve-code', ['code' => $flow['codes'][0]['code']]);
        $login->assertOk();
        $childToken = $login->json('token');
        $this->assertNotEmpty($childToken);

        // Nothing the app can call hands the child anything usable: the
        // request endpoint's response carries no id and no code at all,
        // whichever branch it takes.
        $requestResponse = $this->postJson('/api/consent/request', [
            'email' => 'rodic@example.com', 'locale' => 'cs', 'country' => 'CZ',
        ]);
        $requestResponse->assertOk();
        $this->assertSame(['sent' => true], $requestResponse->json());

        // A genuinely pending consent for this child's own guardian — the
        // exact case an "confirm my guardian's consent from my app session"
        // shortcut would serve, and what this test needs to prove is
        // unreachable from a child session.
        //
        // Opened through the models rather than by calling /consent/request
        // twice: since BR-2JW929 that endpoint answers an already-confirmed
        // guardian with the overview mail and opens no new consent, so this
        // state is now only reachable by two requests racing before either
        // confirms. That is a narrower window, not a closed one — and the
        // property under test (a child session cannot read or confirm it) has
        // to hold whenever it does arise.
        $pending = Consent::startFor(
            Guardian::where('email', 'rodic@example.com')->firstOrFail(),
            'cs',
            'CZ',
        );
        [$emailedCode] = ConsentCode::issue($pending, ConsentCode::PURPOSE_CONFIRM);

        // ...and neither does the child's own session endpoint.
        $me = $this->withToken($childToken)->getJson('/api/user');
        $me->assertOk();
        $this->assertStringNotContainsString($pending->public_id, $me->getContent());
        $this->assertStringNotContainsString($flow['confirmCode'], $me->getContent());

        // Even handed the public id, the session confirms nothing.
        $this->withToken($childToken)
            ->postJson('/api/consent/confirm', ['id' => $pending->public_id, 'code' => 'ZZZZZZ'])
            ->assertStatus(422);

        $this->assertSame(Consent::STATUS_PENDING, $pending->fresh()->status);

        // The code that only ever reaches the parent's inbox, with no session
        // whatsoever, is what confirms.
        $this->postJson('/api/consent/confirm', ['id' => $pending->public_id, 'code' => $emailedCode])
            ->assertOk();

        $this->assertSame(Consent::STATUS_CONFIRMED, $pending->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 3 — revocation locks the child out, data gone within 30 days
    // ─────────────────────────────────────────────────────────────────────

    /**
     * "Nepřihlásí se" is asserted on both directions of a session: the code
     * stops resolving, and the token the child already holds stops working.
     * The second half is the one a lockout that only guards new logins would
     * fail.
     */
    public function test_c3_revocation_locks_the_child_out_immediately(): void
    {
        $flow = $this->runFullFlow();
        $childCode = $flow['codes'][0]['code'];

        $login = $this->postJson('/api/resolve-code', ['code' => $childCode]);
        $login->assertOk();
        $childToken = $login->json('token');

        $this->withToken($childToken)->getJson('/api/user')->assertOk();

        $this->postJson("/api/consent/manage/{$flow['manageToken']}/revoke")->assertOk();

        // Test-harness artifact, not a product concern: the whole test shares
        // one application instance, and Sanctum's RequestGuard memoises the
        // user it resolved for the pre-revocation call above. Production has
        // no such carry-over — every request resolves the bearer token from
        // the database afresh — so the guard is dropped here to make the
        // assertion below actually re-read it.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/resolve-code', ['code' => $childCode])->assertStatus(403);
        $this->withToken($childToken)->getJson('/api/user')->assertStatus(401);
    }

    /**
     * "Do 30 dní neexistují jeho data (ověřit jobem + testem)" — asserted as
     * a window, not a single point: the job must NOT erase inside the grace
     * period (a revocation that deleted immediately would break the parent's
     * ability to change their mind and the promise's own wording), and it
     * MUST have erased once the window closes.
     *
     * The erasure assertion is a sweep over every table in the schema for
     * any surviving reference to the purged accounts, not a hand-written
     * list of tables — a new user-owned table added later without a cascade
     * fails here without anyone remembering to extend this test.
     */
    public function test_c3_data_is_gone_within_thirty_days_and_not_before(): void
    {
        $flow = $this->runFullFlow();
        $guardianId = $flow['consent']->guardian_id;

        $children = User::where('guardian_id', $guardianId)->where('is_minor', true)->get();
        $this->assertCount((int) config('consent.children_per_guardian'), $children);
        $childIds = $children->pluck('id')->all();

        $this->seedChildData($children->first());

        $this->postJson("/api/consent/manage/{$flow['manageToken']}/revoke")->assertOk();

        $revoked = $flow['consent']->fresh();
        $this->assertSame(Consent::STATUS_REVOKED, $revoked->status);
        $this->assertNotNull($revoked->purge_after);
        $this->assertLessThanOrEqual(
            30,
            $revoked->revoked_at->diffInDays($revoked->purge_after),
            'the erasure must be scheduled no later than 30 days after the revocation'
        );

        // Day 29: the job runs and must leave everything alone.
        $this->travel(29)->days();
        $this->artisan('consent:purge-revoked')->assertExitCode(0);
        $this->assertSame(
            count($childIds),
            User::whereIn('id', $childIds)->count(),
            'the grace period must be honoured — nothing is erased before it expires'
        );

        // Day 31: the job runs and everything must be gone.
        $this->travel(2)->days();
        $this->artisan('consent:purge-revoked')->assertExitCode(0);

        $this->assertSame(0, User::whereIn('id', $childIds)->count());
        $this->assertSame(0, User::where('is_minor', true)->count());
        $this->assertDatabaseMissing('guardians', ['id' => $guardianId]);
        $this->assertDatabaseMissing('classrooms', ['guardian_id' => $guardianId]);
        $this->assertDatabaseMissing('consents', ['guardian_id' => $guardianId]);

        $this->assertNoRowsReferenceUsers($childIds);

        $this->travelBack();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 4 — the audit log proves consent, code delivery, revocation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Existence of the three named events is the criterion's letter; this
     * asserts its point as well:
     *
     *  - each event is anchored to the right guardian and consent (a trail
     *    that cannot say WHOSE consent proves nothing);
     *  - the three happen in the order the story requires;
     *  - and — the reason the trail exists at all — the revocation stays
     *    provable AFTER the erasure it authorised has destroyed every other
     *    trace of the family.
     */
    public function test_c4_audit_log_proves_confirmation_code_delivery_and_revocation(): void
    {
        $flow = $this->runFullFlow();
        $consentId = $flow['consent']->id;
        $guardianId = $flow['consent']->guardian_id;

        $this->postJson("/api/consent/manage/{$flow['manageToken']}/revoke")->assertOk();

        $required = ['requested', 'confirmed', 'codes_sent', 'revoked'];

        foreach ($required as $event) {
            $row = ConsentAuditLog::where('event', $event)->where('consent_id', $consentId)->first();

            $this->assertNotNull($row, "missing audit event: {$event}");
            $this->assertSame($guardianId, $row->guardian_id, "audit event {$event} is not anchored to the guardian");
        }

        $idFor = fn (string $event) => ConsentAuditLog::where('event', $event)
            ->where('consent_id', $consentId)->value('id');

        $this->assertTrue($idFor('requested') < $idFor('confirmed'), 'confirmation must follow the request');
        $this->assertTrue($idFor('confirmed') < $idFor('codes_sent'), 'the codes go out after the confirmation');
        $this->assertTrue($idFor('codes_sent') < $idFor('revoked'), 'the revocation must come last');

        // The trail must outlive the data it describes.
        $this->travel(31)->days();
        $this->artisan('consent:purge-revoked')->assertExitCode(0);
        $this->travelBack();

        $this->assertDatabaseMissing('guardians', ['id' => $guardianId]);

        foreach ([...$required, 'purged'] as $event) {
            $this->assertTrue(
                ConsentAuditLog::where('event', $event)->exists(),
                "audit event {$event} did not survive the erasure it describes"
            );
        }

        // Surviving rows are anonymised, not deleted — the event and its
        // timestamp are the proof; the IP is personal data that must not
        // outlive the erasure.
        $this->assertSame(
            0,
            ConsentAuditLog::whereNotNull('ip')->orWhereNotNull('user_agent')->count(),
            'the purge must scrub ip/user_agent from the surviving trail'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 5 — no plaintext code in the DB, no code/child email in logs
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Not "login_code is null" — that only checks the column today's code
     * happens to write. This sweeps EVERY string column of EVERY table in
     * the schema for every secret the flow ever minted (the parent's confirm
     * code, all three child codes, a regenerated child code and the manage
     * token), so a leak into a column nobody thought about — a cached JSON
     * blob, an audit meta field, a new table — fails here too.
     */
    public function test_c5_no_secret_from_the_flow_is_stored_in_plaintext_anywhere(): void
    {
        $flow = $this->runFullFlow();

        $child = User::where('is_minor', true)->orderBy('id')->firstOrFail();
        $regenerated = $this->postJson(
            "/api/consent/manage/{$flow['manageToken']}/regenerate",
            ['child_id' => $child->id]
        );
        $regenerated->assertOk();

        $secrets = array_merge(
            [$flow['confirmCode'], $flow['manageToken'], (string) $regenerated->json('code')],
            array_column($flow['codes'], 'code'),
        );

        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
        }

        $this->assertNoPlaintextInDatabase($secrets);

        // The pseudonymity half of the same criterion: a minor row carries
        // no email and no plaintext code of its own.
        //
        // NOTE: `users.login_code` still holds plaintext for SCHOOL
        // accounts — a documented, deliberate deviation (a teacher must be
        // able to re-read a pupil's code in admin). The criterion's "no
        // plaintext code in the DB" is asserted here for every code this
        // feature mints, which is what the report was about.
        foreach (User::where('is_minor', true)->get() as $minor) {
            $this->assertNull($minor->email, 'a minor account must hold no email');
            $this->assertNull($minor->login_code, 'a minor code must never be stored in plaintext');
            $this->assertNotNull($minor->login_code_hash);
        }
    }

    /**
     * The log half of the criterion, captured for real rather than asserted
     * about one call site: every MessageLogged event raised while the whole
     * flow runs — including the failure paths, where an exception message is
     * most likely to carry something it should not — is collected and
     * searched.
     *
     * Two independent checks, because knowing the plaintext is not always
     * possible:
     *  - no secret this test knows about appears in any logged message or
     *    context;
     *  - and, generically, no 6-character token found anywhere in the log
     *    stream hashes to a login code stored in the database. That one
     *    catches a leaked code even if the leak comes from a path this test
     *    never learned the plaintext of.
     *
     * On the child's email: a minor account has none (asserted above), so
     * "no child email in the logs" is structurally true rather than merely
     * observed. The PARENT's address is a different matter — see the report
     * accompanying this task; report()-ing a mailer exception logs its
     * message, and real SMTP failures embed the recipient. That is outside
     * this criterion, which names the child's email, and is why the
     * deliberate Log::critical() contexts in ConsentController/ConsentService
     * carry no message and no address.
     */
    public function test_c5_no_login_code_ever_reaches_the_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $flow = $this->runFullFlow();

        $child = User::where('is_minor', true)->orderBy('id')->firstOrFail();
        $this->postJson("/api/consent/manage/{$flow['manageToken']}/regenerate", ['child_id' => $child->id])
            ->assertOk();
        $this->getJson("/api/consent/manage/{$flow['manageToken']}")->assertOk();
        $this->postJson("/api/consent/manage/{$flow['manageToken']}/revoke")->assertOk();

        // Now the failure path, which is the one that logs at all: a mailer
        // that throws mid-flow for a second guardian.
        Mail::fake();
        $this->postJson('/api/consent/request', [
            'email' => 'druhy@example.com', 'locale' => 'cs', 'country' => 'CZ',
        ])->assertOk();

        $secondCode = null;
        Mail::assertSent(ConsentRequestMail::class, function ($mail) use (&$secondCode) {
            if ($mail->to[0]['address'] === 'druhy@example.com') {
                $secondCode = $mail->code;
            }

            return true;
        });

        $secondConsent = Consent::where('status', Consent::STATUS_PENDING)->latest('id')->firstOrFail();

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP 550 rejected'));

        $this->postJson('/api/consent/confirm', ['id' => $secondConsent->public_id, 'code' => $secondCode])
            ->assertOk()
            ->assertJson(['confirmed' => true, 'mail_sent' => false]);

        $haystack = implode("\n", $logged);
        $this->assertNotSame('', $haystack, 'the failure path must log something — otherwise this test proves nothing');

        $knownSecrets = array_merge(
            [$flow['confirmCode'], $flow['manageToken'], (string) $secondCode],
            array_column($flow['codes'], 'code'),
        );

        foreach ($knownSecrets as $secret) {
            $this->assertStringNotContainsStringIgnoringCase(
                $secret,
                $haystack,
                'a consent secret reached the logs'
            );
        }

        $this->assertNoLoginCodePreimageIn($haystack);
    }

    /**
     * "App neumožní s emailem propojit" is a claim about the API surface,
     * not about the child's row: every endpoint through which an account
     * could acquire an email address is driven with the child's own live
     * session, and the child must come out of all of them with email still
     * null.
     */
    public function test_c5_no_api_surface_lets_a_child_account_acquire_an_email(): void
    {
        $flow = $this->runFullFlow();

        $login = $this->postJson('/api/resolve-code', ['code' => $flow['codes'][0]['code']]);
        $login->assertOk();
        $childToken = $login->json('token');
        $childId = $login->json('user.id');

        // 1. The guest-upgrade path: refused, the child is not a guest.
        VerificationCode::create([
            'email' => 'dite@example.com',
            'code' => '123456',
            'expires_at' => now()->addMinutes(10),
            'verified_at' => now(),
        ]);

        $this->withToken($childToken)->postJson('/api/guest/claim', [
            'email' => 'dite@example.com', 'name' => 'Skutečné Jméno',
        ])->assertStatus(422);

        // 2. The verified-registration path: refused outright for a minor
        //    caller.
        $this->withToken($childToken)->postJson('/api/register-verified', [
            'email' => 'dite@example.com', 'name' => 'Skutečné Jméno',
        ])->assertStatus(403);

        // 3. The profile update: no email field exists to write.
        $this->withToken($childToken)->putJson('/api/user/profile', [
            'email' => 'dite@example.com', 'avatar_index' => 2,
        ])->assertOk();

        // 4. The email-verification path with the child's token in hand.
        $verification = VerificationCode::generateFor('dite2@example.com', 10);
        $this->withToken($childToken)->postJson('/api/email/verify', [
            'email' => 'dite2@example.com', 'code' => $verification->code,
        ])->assertOk();

        $childAfter = User::findOrFail($childId);
        $this->assertNull($childAfter->email, 'no API surface may attach an email to a minor account');
        $this->assertNull($childAfter->email_verified_at);
        $this->assertTrue((bool) $childAfter->is_minor);
        $this->assertSame(
            0,
            User::whereIn('email', ['dite@example.com', 'dite2@example.com'])->where('is_minor', true)->count()
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 6 — a repeat request must not duplicate the guardian
    // ─────────────────────────────────────────────────────────────────────

    public function test_c6_repeat_request_reuses_the_guardian_and_the_children(): void
    {
        $flow = $this->runFullFlow('rodic@example.com');

        Mail::fake();

        // Same address, different casing — a parent retyping their email is
        // the whole scenario this criterion is about, and case is the most
        // likely way a naive lookup creates a second row.
        $this->postJson('/api/consent/request', [
            'email' => 'RODIC@Example.com', 'locale' => 'cs', 'country' => 'CZ',
        ])->assertOk();

        $this->assertSame(1, Guardian::count(), 'a repeat request must not create a second guardian');
        $this->assertSame(1, Guardian::where('email', 'rodic@example.com')->count());
        $this->assertSame(
            $flow['consent']->guardian_id,
            Consent::latest('id')->firstOrFail()->guardian_id,
            'the new consent must hang off the existing guardian'
        );

        // And the family it belongs to is not duplicated either.
        $this->assertSame((int) config('consent.children_per_guardian'), User::where('is_minor', true)->count());
        $this->assertSame(1, DB::table('classrooms')->where('type', 'self_service')->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criterion 7 — rate limits, verified through the real middleware
    //
    // These tests must NOT disable ThrottleRequests; the throttle IS the
    // subject. (ConsentConfirmTest disables it for one test because there
    // the subject is the per-code attempt budget underneath it.)
    // ─────────────────────────────────────────────────────────────────────

    public function test_c7_consent_request_is_rate_limited_per_email(): void
    {
        Mail::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/consent/request', [
                'email' => 'limit@example.com', 'locale' => 'cs', 'country' => 'CZ',
            ])->assertOk();
        }

        $this->postJson('/api/consent/request', [
            'email' => 'limit@example.com', 'locale' => 'cs', 'country' => 'CZ',
        ])->assertStatus(429);

        // Keyed per email, not globally: one abused address must not lock
        // every other parent out of the feature.
        $this->postJson('/api/consent/request', [
            'email' => 'jiny@example.com', 'locale' => 'cs', 'country' => 'CZ',
        ])->assertOk();
    }

    public function test_c7_consent_confirm_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/consent/confirm', [
                'id' => '00000000-0000-4000-8000-000000000000', 'code' => 'AB12CD',
            ])->assertStatus(422);
        }

        $this->postJson('/api/consent/confirm', [
            'id' => '00000000-0000-4000-8000-000000000000', 'code' => 'AB12CD',
        ])->assertStatus(429);
    }

    public function test_c7_manage_link_is_rate_limited(): void
    {
        $flow = $this->runFullFlow();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson("/api/consent/manage/{$flow['manageToken']}")->assertOk();
        }

        $this->getJson("/api/consent/manage/{$flow['manageToken']}")->assertStatus(429);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** Give the child something to lose, so criterion 3's sweep has teeth. */
    private function seedChildData(User $child): void
    {
        UserStats::create(['user_id' => $child->id, 'level' => 2, 'xp_points' => 120]);
        UserProgress::create([
            'user_id' => $child->id, 'course_id' => 'demo-course', 'lesson_id' => 'l1', 'completed' => true,
        ]);
        QuizAttempt::create([
            'user_id' => $child->id, 'course_id' => 'demo-course',
            'total_questions' => 5, 'correct_answers' => 4, 'score_percent' => 80,
        ]);
        PracticeCard::create([
            'user_id' => $child->id, 'course_id' => 'demo-course',
            'lesson_id' => 'l1', 'block_id' => 'b1', 'source_type' => 'quiz',
        ]);
        $session = ChatSession::create(['user_id' => $child->id, 'persona' => 'tutor']);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'ahoj']);
        DebugReport::create([
            'user_id' => $child->id, 'app_version' => '1.0', 'platform' => 'web',
            'report_data' => ['note' => 'test'],
        ]);
    }

    /**
     * Assert no row anywhere still points at any of these user ids.
     *
     * Walks the live schema rather than a hand-written table list, so a
     * user-owned table added later without a cascade is caught here.
     *
     * consent_audit_logs is exempt by design: the trail outlives the
     * accounts (decision 9) and its `meta` keeps pseudonymous child ids that
     * no longer resolve to anything. It has no user_id column, so it is not
     * matched by the column names below either way — noted so the exemption
     * is deliberate rather than accidental.
     *
     * @param  array<int, int>  $userIds
     */
    private function assertNoRowsReferenceUsers(array $userIds): void
    {
        $columns = ['user_id', 'student_id', 'tokenable_id', 'source_user_id', 'target_user_id', 'created_by'];

        foreach ($this->schemaTables() as $table) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $this->assertSame(
                    0,
                    DB::table($table)->whereIn($column, $userIds)->count(),
                    "erased accounts are still referenced by {$table}.{$column}"
                );
            }
        }
    }

    /**
     * Assert none of these secrets appears in any string column of any
     * table.
     *
     * @param  array<int, string>  $secrets
     */
    private function assertNoPlaintextInDatabase(array $secrets): void
    {
        foreach ($this->schemaTables() as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsStringIgnoringCase(
                            $secret,
                            $value,
                            "a plaintext secret is stored in {$table}.{$column}"
                        );
                    }
                }
            }
        }
    }

    /**
     * Generic leak detector: take every 6-character alphanumeric window in
     * $haystack, hash it the way a login code is hashed, and assert none of
     * them matches a code stored in the database. Catches a leaked code even
     * when the test never knew its plaintext.
     */
    private function assertNoLoginCodePreimageIn(string $haystack): void
    {
        $hashes = DB::table('users')->whereNotNull('login_code_hash')->pluck('login_code_hash')->all();

        if ($hashes === []) {
            return;
        }

        $hashes = array_flip($hashes);
        $seen = [];

        preg_match_all('/[0-9A-Za-z]+/', $haystack, $matches);

        foreach ($matches[0] as $run) {
            $length = strlen($run);

            for ($offset = 0; $offset + 6 <= $length; $offset++) {
                $candidate = substr($run, $offset, 6);

                if (isset($seen[$candidate])) {
                    continue;
                }

                $seen[$candidate] = true;

                $this->assertArrayNotHasKey(
                    StudentCodes::hash($candidate),
                    $hashes,
                    "the log stream contains a live login code ({$candidate})"
                );
            }
        }
    }

    /** @return array<int, string> */
    private function schemaTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $name) => $name === 'migrations' || str_starts_with($name, 'sqlite_'))
            ->values()
            ->all();
    }
}
