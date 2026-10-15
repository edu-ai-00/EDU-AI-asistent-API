<?php

namespace App\Services;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentManageLinkMail;
use App\Mail\ConsentRequestMail;
use App\Mail\ConsentRevokedMail;
use App\Models\Classroom;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\ConsentCode;
use App\Models\Guardian;
use App\Models\User;
use App\Support\StudentCodes;
use App\Support\StudentNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

/**
 * Every state change of a parental consent goes through here so the audit
 * trail can never be bypassed.
 */
class ConsentService
{
    private const LOCALE_COUNTRIES = [
        'cs' => 'CZ',
        'sk' => 'SK',
    ];

    /**
     * Open a consent request for a guardian email and mail them the code.
     * Idempotent only on the guardian row: a repeat request reuses the
     * existing guardian, but any of that guardian's still-pending consents
     * are superseded (marked expired, their live confirm code invalidated)
     * before a fresh one is opened — a guardian never has more than one
     * live confirm code at a time.
     *
     * A guardian whose governing consent is already CONFIRMED is not asked
     * to consent a second time (BR-2JW929): the second child in a family
     * hits this endpoint too, and this feature provisions
     * config('consent.children_per_guardian') accounts per guardian on the
     * first confirmation — so there is nothing new to consent to, and the
     * old behaviour (a fresh request mail, then a "confirmed" mail with no
     * codes in it if the parent actually confirmed) was pure noise. That
     * guardian gets sendChildOverview()'s mail instead, and this method
     * returns the existing consent with no new row and no new confirm code.
     *
     * A REVOKED governing consent deliberately falls through to the normal
     * path: consent that was withdrawn has to be given again.
     */
    public function requestConsent(
        string $email,
        string $locale,
        ?string $country,
        Request $httpRequest,
    ): Consent {
        $email = strtolower(trim($email));

        $guardian = Guardian::firstOrCreate(
            ['email' => $email],
            ['locale' => $locale, 'country' => $country],
        );

        // governingFor(), not "any confirmed consent": it resolves the single
        // authoritative row (see its docblock), so a guardian who revoked and
        // still has an older CONFIRMED row lying around is correctly sent
        // down the ask-again path rather than shortcut here.
        $governing = Consent::governingFor($guardian->id);

        if ($governing?->isConfirmed()) {
            $this->sendChildOverview($governing, $locale, $httpRequest);

            return $governing;
        }

        $consent = DB::transaction(function () use ($guardian, $locale, $country, $httpRequest) {
            $consent = Consent::startFor($guardian, $locale, $country);

            $this->supersedePendingConsents($guardian, $consent, $httpRequest);

            return $consent;
        });

        [$plainCode] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        // Written before the send: the audit trail must survive a mail
        // outage, not just a successful send. A follow-up 'mail_failed'
        // event below covers the failure case.
        ConsentAuditLog::record($consent, 'requested', $httpRequest, [
            'text_version' => $consent->text_version,
            'country' => $country,
        ]);

        try {
            Mail::to($email)->send(new ConsentRequestMail(
                code: $plainCode,
                confirmUrl: $this->confirmUrl($consent, $plainCode),
                textVersion: $consent->text_version,
                ttlHours: (int) config('consent.confirm_code_ttl_hours'),
                locale: $locale,
                ageThreshold: $this->ageThreshold($country, $locale),
            ));
        } catch (Throwable $e) {
            ConsentAuditLog::record($consent, 'mail_failed', $httpRequest, [
                'text_version' => $consent->text_version,
            ]);

            // Re-thrown: the controller catches Throwable and keeps the
            // response constant, but it also needs to know this happened so
            // it can log/report it.
            throw $e;
        }

        return $consent;
    }

    /**
     * Tell a guardian who has already consented what accounts exist under
     * that consent, and give them a live manage link to act on them
     * (BR-2JW929). Reuses ConsentGrantedMail rather than introducing a
     * fourth template: from the parent's side this is the same statement —
     * "your consent is in place, here are the accounts it covers" — and its
     * no-new-codes branch already says exactly that.
     *
     * Names only, no codes: child login codes are stored as hashes
     * (uniqueChildCode()), so re-sending one is not merely withheld here, it
     * is impossible. The manage link is the only route back to a working
     * code, via ConsentManageController::regenerate().
     *
     * Mails in the locale of the request that triggered it, not
     * $consent->locale: the consent row's locale is frozen at whatever the
     * *first* registration used, which for a family whose second child
     * registers in another language means the parent gets an email in a
     * language nobody on this device is using. Nothing legally load-bearing
     * is quoted in this mail — no consent text, no version — so the current
     * language is simply the better guess. The consent's own locale still
     * governs the mails that do quote the legal text.
     *
     * Token handling mirrors sendManageLink() exactly: issue without
     * invalidating predecessors, send, and only kill the older tokens once
     * the send has actually returned — a failed send must not strip a
     * guardian of the working manage link they already have.
     */
    private function sendChildOverview(Consent $consent, string $locale, Request $httpRequest): void
    {
        [$token, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE, invalidatePredecessors: false);

        $children = User::where('guardian_id', $consent->guardian_id)
            ->where('is_minor', true)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        // Written before the send, like every other audit row in this class,
        // so the trail survives a mail outage. Count only — the names are
        // pseudonymous, but there is no reason to copy them here.
        ConsentAuditLog::record($consent, 'overview_sent', $httpRequest, [
            'children' => count($children),
        ]);

        try {
            Mail::to($consent->guardian->email)->send(new ConsentGrantedMail(
                children: [],
                manageUrl: $this->manageUrl($token),
                locale: $locale,
                existingChildren: $children,
            ));
        } catch (Throwable $e) {
            ConsentAuditLog::record($consent, 'mail_failed', $httpRequest, [
                'text_version' => $consent->text_version,
            ]);

            // Re-thrown for the same reason requestConsent() rethrows: the
            // controller catches Throwable and keeps the response constant,
            // but still needs to know this happened so it can log/report it.
            throw $e;
        }

        ConsentCode::invalidateOthers($consent, ConsentCode::PURPOSE_MANAGE, $row);
    }

    /**
     * Confirm a pending consent with the code the parent typed. On success it
     * provisions the guardian's class and child accounts (topping up to the
     * configured count rather than always creating a fresh batch — see
     * provisionChildren()) and mails the codes.
     *
     * Returns null when the code is wrong, spent, expired, or the consent is
     * no longer pending — the caller must not distinguish between those.
     *
     * @return array{consent: Consent, children: int, mail_sent: bool}|null
     *                                                                      children is the guardian's total minor count, not the number
     *                                                                      newly created by this call — on a top-up cycle that delta is
     *                                                                      0, and a parent who already has three children must not be
     *                                                                      told they have zero.
     */
    public function confirm(string $publicId, string $code, Request $httpRequest): ?array
    {
        $consent = Consent::where('public_id', $publicId)->first();

        if (! $consent || $consent->status !== Consent::STATUS_PENDING) {
            ConsentAuditLog::record($consent, 'failed_attempt', $httpRequest, ['reason' => 'not_pending']);

            return null;
        }

        // The primary guard against a double-tapped Submit: redeem() claims
        // used_at with a single atomic UPDATE, so at most one concurrent call
        // for this consent can pass this line. The status check above is the
        // second, coarser guard for a sequential retry after the first one
        // already committed. Neither guard protects against a *second full
        // request → confirm cycle* producing a fresh, genuinely pending
        // consent for the same guardian — that is provisionChildren()'s job.
        $redeemed = ConsentCode::redeem($consent, $code, ConsentCode::PURPOSE_CONFIRM);

        if (! $redeemed) {
            ConsentAuditLog::record($consent, 'failed_attempt', $httpRequest, ['reason' => 'bad_code']);

            return null;
        }

        try {
            $provisioned = DB::transaction(function () use ($consent, $httpRequest) {
                // A guardian must have at most one governing consent
                // (BR-8FDF37 C1) — requestConsent()'s supersedePendingConsents()
                // only retires PENDING rows, so without this a second full
                // request→confirm cycle (see provisionChildren() for how one
                // still arises after BR-2JW929) left the guardian with two
                // simultaneously CONFIRMED rows. CodeController's
                // revoked-consent check and ConsentService::revoke() are both
                // hardened against that state too, but this is what prevents
                // it from being created in the first place.
                //
                // Called BEFORE $consent's own status flips below, not after:
                // supersedeConfirmedConsents()'s query already excludes
                // $consent by id regardless of its status, so this ordering
                // does not change which rows it retires — but it is what
                // makes the lock acquisition order genuinely ascend
                // end-to-end. $consent is always the highest id in the
                // guardian's consent set (it was just created by
                // requestConsent()), so sweeping the OTHER rows ascending
                // first and only then touching this highest-id row makes
                // confirm()'s real sequence 1, 2, ..., N-1, N — the same
                // ascending order revoke()'s orderBy('id')->lockForUpdate()
                // takes. Flipping this row's status first, as an earlier
                // version of this method did, made the real sequence
                // N, 1, 2, ..., N-1 instead: ordering supersede's own query
                // alone narrowed the deadlock window without closing it,
                // since a concurrent revoke()-grabs-1 / confirm()-grabs-N
                // could still each hold one lock and block on the other's.
                $this->supersedeConfirmedConsents($consent->guardian, $consent, $httpRequest);

                $consent->forceFill([
                    'status' => Consent::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                ])->save();

                $provisioned = $this->provisionChildren($consent);

                // Audited in-transaction: three accounts and a classroom
                // otherwise come into existence with no event naming them.
                // users.guardian_id is not a substitute — it disappears when
                // Task 10 erases the accounts, and this table is designed to
                // outlive them. Pseudonymous ids only, per the class docblock.
                ConsentAuditLog::record($consent, 'children_provisioned', $httpRequest, [
                    'classroom_id' => $provisioned['classroom']->id,
                    'child_ids' => array_column($provisioned['children'], 'id'),
                ]);

                // Moved into the transaction, next to the status flip it
                // describes: this is the legally load-bearing record of the
                // whole feature, and it must survive a mail outage the same
                // way the 'requested' audit row in requestConsent() does —
                // not sit behind an SMTP call where a worker killed mid-send
                // loses it.
                ConsentAuditLog::record($consent, 'confirmed', $httpRequest, [
                    'text_version' => $consent->text_version,
                ]);

                return $provisioned;
            });
        } catch (Throwable $e) {
            // The DB::transaction() above already rolled back the status
            // flip and any partial provisioning, but the confirm code was
            // burned by redeem() before this block ever ran and that is not
            // undone. Without this, a rollback here would leave the code
            // spent, nothing created, and no trace of why.
            ConsentAuditLog::record($consent, 'provision_failed', $httpRequest, [
                'text_version' => $consent->text_version,
            ]);

            throw $e;
        }

        $children = $provisioned['children'];
        $totalChildren = $provisioned['total'];
        $mailSent = false;

        // Both the manage-token issue and the mail send live inside this try:
        // if issue() throws after the transaction above already committed,
        // the catch below still records the failure and the request still
        // returns success for the confirmation itself (see the comment on
        // the catch). Previously issue() sat outside the try, so a failure
        // there discarded the three plaintext codes — the only copy in
        // existence — with the stack frame, wrote no mail and no audit row.
        try {
            [$manageToken] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE);

            Mail::to($consent->guardian->email)->send(new ConsentGrantedMail(
                children: array_map(
                    fn (array $child) => ['name' => $child['name'], 'code' => $child['code']],
                    $children,
                ),
                manageUrl: $this->manageUrl($manageToken),
                locale: $consent->locale,
                // Only rendered when $children is empty — the top-up cycle,
                // where naming the existing accounts is the whole content of
                // the mail (BR-2JW929).
                existingChildren: $provisioned['existing_names'],
            ));

            $mailSent = true;

            ConsentAuditLog::record($consent, 'codes_sent', $httpRequest, [
                'children' => count($children),
            ]);
        } catch (Throwable $e) {
            ConsentAuditLog::record($consent, 'mail_failed', $httpRequest, [
                'text_version' => $consent->text_version,
            ]);

            // Not rethrown: unlike requestConsent(), by this point the
            // consent is already confirmed and the child accounts already
            // exist. Propagating the exception would turn a successful,
            // committed confirmation into a 500 the caller might mistake for
            // "nothing happened" and retry — retrying only burns the
            // now-single-used code (and, per the comment on
            // provisionChildren(), tops up rather than duplicates, but a
            // confused parent retrying is still the most likely trigger for
            // that path). report()/Log::critical here are the operational
            // signal in place of the rethrow-and-catch pattern
            // requestConsent() uses. mail_sent=false is surfaced to the
            // caller instead, so the controller can tell the parent to
            // expect a resend rather than an inbox that will stay empty.
            report($e);
            Log::critical('consent.confirm.mail_failed', [
                'exception_class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return ['consent' => $consent, 'children' => $totalChildren, 'mail_sent' => $mailSent];
    }

    /**
     * Create (or top up) the guardian's self-service class and its
     * pseudonymous children. Idempotent per **guardian**, not per consent: a
     * second full request → confirm cycle for the same guardian reaches here
     * past both of confirm()'s guards (different consent, fresh code).
     *
     * BR-2JW929 narrowed how that second cycle arises — requestConsent() no
     * longer opens a consent at all for a guardian whose governing consent is
     * CONFIRMED, so the everyday routes to it (a parent who lost the original
     * email, a new phone, the mail-failure recovery path) now end in an
     * overview mail and a manage link instead. It did not close it: two
     * requests racing before either confirms still produce two pending
     * consents, and a guardian who revoked and consented again legitimately
     * runs a second cycle. This method must stay idempotent for those.
     *
     * firstOrCreate() on the classroom dedups the classroom, but nothing
     * else — it is not, on its own, a guard against creating a second batch
     * of children. The count-and-top-up logic below is what actually keeps a
     * guardian at config('consent.children_per_guardian') across repeated
     * confirmations, by counting existing minors under this guardian and
     * only creating the shortfall.
     *
     * @return array{classroom: Classroom, children: array<int, array{id: int, name: string, code: string}>, existing_names: array<int, string>, total: int}
     *                                                                                                                                                       children plaintext codes exist only long enough to be emailed;
     *                                                                                                                                                       existing_names are the accounts that were already there before
     *                                                                                                                                                       this call (empty on a first confirmation); total is the
     *                                                                                                                                                       guardian's minor count *after* this call, which on a top-up (no
     *                                                                                                                                                       new children) is the same as before it
     */
    private function provisionChildren(Consent $consent): array
    {
        $guardian = $consent->guardian;

        // Name is deliberately non-identifying, not the guardian's email:
        // AdminClassroomController::index applies no type filter today, so
        // an admin's classroom list would otherwise be a list of parent
        // email addresses; the guardian's email already lives on
        // `guardians`, which has different retention than `classrooms`, so
        // copying it here is a second copy of personal data in a feature
        // whose whole point is minimising what we hold about families; and
        // firstOrCreate()'s attributes only apply on create, so a copied
        // email goes stale the moment the guardian's address changes.
        $classroom = Classroom::firstOrCreate(
            ['guardian_id' => $guardian->id, 'type' => 'self_service'],
            ['name' => "Samoobsluha #{$guardian->id}"],
        );

        $target = (int) config('consent.children_per_guardian');

        // Names, not just a count: on the top-up cycle no new child is
        // created, and the granted mail's no-new-codes branch names the
        // accounts that already exist so the parent knows which child to
        // regenerate a code for on the manage page (BR-2JW929).
        $existingNames = User::where('guardian_id', $guardian->id)
            ->where('is_minor', true)
            ->orderBy('id')
            ->pluck('name')
            ->all();
        $existing = count($existingNames);

        $children = [];

        for ($i = 0; $i < max(0, $target - $existing); $i++) {
            [$code, $hash] = $this->uniqueChildCode();

            $child = User::create([
                'name' => StudentNames::generate(),
                'is_minor' => true,
                'guardian_id' => $guardian->id,
                'login_code_hash' => $hash,
            ]);
            $child->classrooms()->attach($classroom->id);

            $children[] = ['id' => $child->id, 'name' => $child->name, 'code' => $code];
        }

        return [
            'classroom' => $classroom,
            'children' => $children,
            'existing_names' => $existingNames,
            'total' => $existing + count($children),
        ];
    }

    /**
     * Issue a fresh login code for one child. The previous code stops working
     * the moment the hash is overwritten — there is nothing else to revoke.
     * Returns the plaintext, which the caller shows once and never stores.
     */
    public function regenerateChildCode(Consent $consent, User $child, Request $httpRequest): string
    {
        [$code, $hash] = $this->uniqueChildCode();

        $child->forceFill(['login_code_hash' => $hash])->save();
        $child->tokens()->delete();

        ConsentAuditLog::record($consent, 'code_regenerated', $httpRequest, [
            'child_id' => $child->id,
        ]);

        return $code;
    }

    /**
     * Revoke a consent: every live login code and manage token is
     * invalidated and every child's Sanctum tokens are killed immediately,
     * so nothing already issued keeps working. New logins are refused too —
     * CodeController::resolve() rejects a minor whose guardian's governing
     * consent (Consent::governingFor()) is not CONFIRMED, and this method is
     * what flips that row to REVOKED. The data itself is only queued for
     * erasure after the grace period — nothing is deleted here, the purge
     * command owns that.
     *
     * Revokes EVERY consent currently CONFIRMED for this guardian, not just
     * the $consent argument (which is whichever row the manage-link token
     * happened to resolve to). ConsentService::confirm() now supersedes a
     * prior CONFIRMED row on every fresh confirmation, so a guardian should
     * never hold two at once going forward — but this is belt and braces
     * for rows that predate that fix, or for any write path that skips the
     * service: a stale second CONFIRMED row must not survive a revocation
     * of the one the guardian actually acted on and keep authorising
     * logins (BR-8FDF37 C1).
     */
    public function revoke(Consent $consent, Request $httpRequest): void
    {
        DB::transaction(function () use ($consent, $httpRequest) {
            // Lock ordering: consents before consent_codes, per the class's
            // documented invariant — and, within consents, ascending by id.
            // confirm() takes row locks (implicitly, via ->update()) over
            // the same guardian's CONFIRMED rows too, via
            // supersedeConfirmedConsents() followed by its own row's flip —
            // deliberately in that order, so its real traversal is also
            // ascending end-to-end (see the comment there). Without both
            // paths agreeing on one traversal order, a genuinely concurrent
            // confirm() and revoke() for one guardian could each acquire
            // their first row's lock and then block waiting for the
            // other's — the textbook deadlock shape.
            $confirmedConsents = Consent::where('guardian_id', $consent->guardian_id)
                ->where('status', Consent::STATUS_CONFIRMED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Always include the resolved consent itself, even on the
            // (currently unreachable via the controller, since resolve()
            // rejects an already-revoked token) chance it is not CONFIRMED —
            // the audit trail and code invalidation below must still anchor
            // on the row the guardian actually acted on.
            if (! $confirmedConsents->contains('id', $consent->id)) {
                $confirmedConsents->push($consent);
            }

            foreach ($confirmedConsents as $row) {
                $row->forceFill([
                    'status' => Consent::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'purge_after' => now()->addDays((int) config('consent.purge_after_days')),
                ])->save();

                // Recorded in-transaction, immediately next to the status
                // flip — this is the legal proof a GDPR Art. 7(3)/17
                // revocation was honoured, and it must survive a mail
                // outage the same way 'confirmed' does in confirm().
                // Previously this sat after the Mail::send() call below with
                // no try/catch around either: a failed send threw, the
                // transaction (status, tokens, codes) had already
                // committed, but this row was never written and the
                // guardian saw a 500 with no live manage token left to
                // retry — and request-link only reissues for a *confirmed*
                // consent, so there was no way back in.
                ConsentAuditLog::record($row, 'revoked', $httpRequest);
            }

            // Kill every live session for this guardian's children in one
            // statement rather than per-child deletes.
            $childIds = User::where('guardian_id', $consent->guardian_id)->pluck('id');

            PersonalAccessToken::where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $childIds)
                ->delete();

            // Invalidate every outstanding code/token for every consent just
            // revoked, including the manage token that authorised this very
            // call — the manage page dies with the revocation it just
            // performed.
            ConsentCode::whereIn('consent_id', $confirmedConsents->pluck('id'))
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);
        });

        try {
            Mail::to($consent->guardian->email)->send(new ConsentRevokedMail(
                purgeAfterDays: (int) config('consent.purge_after_days'),
                locale: $consent->locale,
            ));
        } catch (Throwable $e) {
            ConsentAuditLog::record($consent, 'mail_failed', $httpRequest);

            // Not rethrown: unlike sendManageLink() below, there is no
            // constant-response contract to protect here — the revocation
            // already committed above (status flipped, tokens killed, codes
            // invalidated, purge scheduled) and is real regardless of
            // whether the confirmation mail arrives. Surfacing this as a
            // 500 would tell the guardian the revocation failed when it did
            // not, and — per the docblock above — they would have no live
            // manage token left to retry with.
            report($e);
            Log::critical('consent.revoke.mail_failed', [
                'exception_class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Email a fresh magic link for an existing confirmed consent. Mirrors
     * requestConsent(): the audit row is written before the send so it
     * survives a mail outage, and a failed send is recorded and rethrown —
     * the caller (ConsentManageController::requestLink()) is responsible
     * for catching it and keeping the response constant, exactly as
     * ConsentController::request() does for the initial consent request.
     * Without that, a known guardian address (mail send attempted, can
     * throw) was distinguishable from an unknown one (never attempted,
     * never throws) by response status alone — a cleaner oracle than any
     * timing side channel.
     *
     * The old manage token is deliberately kept alive until after the send
     * succeeds: issue(..., invalidatePredecessors: false) creates the new
     * token without touching the old one, and invalidateOthers() only runs
     * once Mail::send() has actually returned. A failed send below throws
     * before that line ever runs, so the guardian's previous — still
     * working — manage link survives the outage instead of being stripped
     * the moment this method was called, with only 3 retries/hour to get a
     * replacement. This briefly leaves two manage tokens live at once on
     * the success path too, which is strictly better than a guardian left
     * with none. Wrapping issue() and the send in one transaction (so a
     * failure rolls the invalidation back) was considered and rejected: it
     * would hold a `consents` row lock open across an SMTP call, and a hung
     * mail server would then pin that lock for the duration.
     */
    public function sendManageLink(Consent $consent, Request $httpRequest): void
    {
        [$token, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE, invalidatePredecessors: false);

        ConsentAuditLog::record($consent, 'link_sent', $httpRequest);

        try {
            Mail::to($consent->guardian->email)->send(new ConsentManageLinkMail(
                manageUrl: $this->manageUrl($token),
                locale: $consent->locale,
            ));
        } catch (Throwable $e) {
            ConsentAuditLog::record($consent, 'mail_failed', $httpRequest);

            // Re-thrown: the controller catches Throwable and keeps the
            // response constant, but it also needs to know this happened so
            // it can log/report it — same contract as requestConsent(). The
            // old manage token is still live at this point — see the
            // docblock above.
            throw $e;
        }

        // Only now, with the new link actually delivered, kill the old one.
        ConsentCode::invalidateOthers($consent, ConsentCode::PURPOSE_MANAGE, $row);
    }

    /**
     * Generate a child login code that collides with neither an existing
     * minor's hash nor a school student's plaintext login_code. Task 9 made
     * CodeController::resolve() look up ONLY by login_code_hash and gives
     * every coded account a hash (school students keep the plaintext too,
     * written by AdminClassroomController and kept in sync by User's
     * saving-hook), so the login_code_hash check alone is now the primary
     * guard — a raw plaintext collision is structurally impossible to reach
     * without also being a hash collision.
     *
     * Bounded at 10 attempts: with a ~32^6 code space a real collision here
     * should be vanishingly rare, so hitting the bound means something is
     * actually broken (e.g. a degenerate RNG) and this should fail loudly
     * rather than loop forever.
     *
     * @return array{0: string, 1: string} [plaintext code, its hash]
     */
    private function uniqueChildCode(): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->generateCandidateCode();
            $hash = StudentCodes::hash($code);

            // orWhere('login_code', $code) is defence-in-depth, not the
            // primary guard: it only matters if some row somehow has a
            // plaintext login_code with no matching login_code_hash (the
            // exact pre-Task-9 state this whole feature closed off). Kept
            // so a future regression that reintroduces that state fails
            // safe here too, instead of silently reproducing the original
            // bug this comment used to describe.
            $collides = User::where('login_code_hash', $hash)
                ->orWhere('login_code', $code)
                ->exists();

            if (! $collides) {
                return [$code, $hash];
            }
        }

        throw new RuntimeException('Could not generate a unique child login code after 10 attempts.');
    }

    /**
     * Thin wrapper around StudentCodes::generate() so a test can force a
     * specific candidate sequence (via partialMock()) to deterministically
     * exercise the collision-retry branch above — a real collision in the
     * ~32^6 code space is otherwise far too rare to trigger on demand.
     */
    protected function generateCandidateCode(): string
    {
        return StudentCodes::generate();
    }

    /**
     * Mark every other still-pending consent this guardian has as expired and
     * burn its live confirm code. Without this, each repeat request left an
     * earlier, still-valid code alive alongside the new one — every extra
     * pending consent is another 6-char code an attacker gets to guess.
     *
     * Each expiry is itself an audited state change — the class docblock's
     * promise that nothing bypasses the trail includes this path — so every
     * superseded consent gets a 'superseded' event carrying the id of the
     * consent that replaced it, keeping the chain reconstructable.
     */
    private function supersedePendingConsents(Guardian $guardian, Consent $supersedingConsent, Request $httpRequest): void
    {
        $stale = Consent::where('guardian_id', $guardian->id)
            ->where('status', Consent::STATUS_PENDING)
            ->where('id', '!=', $supersedingConsent->id)
            ->get();

        foreach ($stale as $consent) {
            $consent->update(['status' => Consent::STATUS_EXPIRED]);

            ConsentCode::where('consent_id', $consent->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            ConsentAuditLog::record($consent, 'superseded', $httpRequest, [
                'superseded_by_consent_id' => $supersedingConsent->id,
            ]);
        }
    }

    /**
     * The CONFIRMED-state counterpart of supersedePendingConsents() above:
     * mark every OTHER consent this guardian already holds in CONFIRMED
     * status as expired and burn its live codes (both purposes — a stale
     * consent's manage link must die with it, the same as a stale pending
     * consent's confirm code does). Called from confirm(), inside its
     * transaction, right after the new consent's own status flips to
     * confirmed, so a guardian can never end up with two simultaneously
     * CONFIRMED rows (BR-8FDF37 C1) — that state is what let a revocation
     * of one row leave the other, unrevoked, still authorising logins via
     * the "does any confirmed consent exist" check CodeController used to
     * run. Same audit shape as supersedePendingConsents(): a 'superseded'
     * event per retired row, carrying the id of the consent that replaced
     * it.
     *
     * Ordered ascending by id, matching revoke()'s lockForUpdate() query
     * over the same guardian_id — both paths take their row locks (here,
     * implicitly via ->update()) in the same traversal order so a
     * genuinely concurrent confirm() and revoke() for one guardian cannot
     * deadlock by acquiring their respective first rows and then blocking
     * on each other's.
     */
    private function supersedeConfirmedConsents(Guardian $guardian, Consent $supersedingConsent, Request $httpRequest): void
    {
        $stale = Consent::where('guardian_id', $guardian->id)
            ->where('status', Consent::STATUS_CONFIRMED)
            ->where('id', '!=', $supersedingConsent->id)
            ->orderBy('id')
            ->get();

        foreach ($stale as $consent) {
            $consent->update(['status' => Consent::STATUS_EXPIRED]);

            ConsentCode::where('consent_id', $consent->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            ConsentAuditLog::record($consent, 'superseded', $httpRequest, [
                'superseded_by_consent_id' => $supersedingConsent->id,
            ]);
        }
    }

    /**
     * Resolve the age of consent. Falls back to the locale's country when the
     * caller did not supply one — a Slovak-locale email with no country must
     * still say 16, not the CZ/global default of 15, or it understates the
     * legal requirement in a legal document.
     *
     * When neither an explicit country nor the locale map resolves one at all
     * (e.g. locale=en with no country — 'en' is a valid locale but maps to no
     * country), fall back to the *highest* configured threshold rather than
     * default_age_threshold. Failing conservative is the right direction for
     * a legal statement: telling a parent the limit is higher than their
     * country's is harmless, telling them it is lower understates the law.
     */
    public function ageThreshold(?string $country, ?string $locale = null): int
    {
        $thresholds = (array) config('consent.age_thresholds');
        $resolvedCountry = $country ?: (self::LOCALE_COUNTRIES[$locale] ?? null);
        $key = $resolvedCountry !== null ? strtoupper($resolvedCountry) : null;

        if ($key !== null && array_key_exists($key, $thresholds)) {
            return (int) $thresholds[$key];
        }

        return $thresholds === []
            ? (int) config('consent.default_age_threshold')
            : (int) max($thresholds);
    }

    /**
     * The link the parent clicks in the request email.
     *
     * Passing $code appends it so the confirm page can prefill the field and
     * the parent never retypes what the same email already shows (BR-FBD2E9).
     * That is a convenience only — the page still requires an explicit submit.
     * Auto-submitting on load would let an email link-scanner (SafeLinks and
     * friends prefetch every URL they see) burn a ConsentCode attempt, or
     * grant a legally load-bearing consent no human ever saw.
     *
     * No secret is newly exposed: the code is printed in the body of the very
     * email that carries this link.
     */
    public function confirmUrl(Consent $consent, ?string $code = null): string
    {
        $url = $this->baseUrl().'/consent/confirm?id='.$consent->public_id;

        return $code === null || $code === ''
            ? $url
            : $url.'&code='.rawurlencode($code);
    }

    public function manageUrl(string $token): string
    {
        return $this->baseUrl().'/consent/manage/'.$token;
    }

    /**
     * config('consent.web_base_url') has an env default, but an explicitly
     * empty CONSENT_WEB_URL beats it — that would silently build a hostless
     * link (e.g. "/consent/confirm?id=..."). A parent getting a mail with a
     * dead link is worse than the request failing loudly, so this throws
     * instead of returning something that merely looks like a URL.
     */
    private function baseUrl(): string
    {
        $base = rtrim((string) config('consent.web_base_url'), '/');

        if (! str_starts_with($base, 'http')) {
            throw new RuntimeException('consent.web_base_url must be an absolute http(s) URL.');
        }

        return $base;
    }
}
