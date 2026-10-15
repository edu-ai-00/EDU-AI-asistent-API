<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\ConsentCode;
use App\Models\Guardian;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ConsentCodeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each call creates its own guardian (unique email) unless one is passed
     * in, so tests that need more than one consent — the normalization loop,
     * e.g. — don't collide on the guardians.email unique constraint, while
     * tests that specifically want two consents of the *same* guardian
     * (revoke, then re-request) can pass theirs through.
     */
    private function pendingConsent(?Guardian $guardian = null): Consent
    {
        $guardian ??= Guardian::create([
            'email' => Str::uuid().'@example.com',
            'locale' => 'cs',
        ]);

        return Consent::startFor($guardian, 'cs', 'CZ');
    }

    public function test_start_for_creates_pending_consent_with_public_id(): void
    {
        $consent = $this->pendingConsent();

        $this->assertSame(Consent::STATUS_PENDING, $consent->status);
        $this->assertNotEmpty($consent->public_id);
        $this->assertSame(config('consent.text_version'), $consent->text_version);
    }

    public function test_issue_returns_plaintext_once_and_stores_only_the_hash(): void
    {
        $consent = $this->pendingConsent();

        [$plain, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $this->assertSame(6, strlen($plain));
        $this->assertSame(StudentCodes::hash($plain), $row->code_hash);
        $this->assertDatabaseMissing('consent_codes', ['code_hash' => $plain]);
    }

    public function test_issuing_a_new_code_invalidates_the_previous_one(): void
    {
        $consent = $this->pendingConsent();

        [, $first] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        [, $second] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $this->assertNotNull($first->fresh()->invalidated_at);
        $this->assertNull($second->fresh()->invalidated_at);
    }

    public function test_code_is_burned_after_max_attempts(): void
    {
        $consent = $this->pendingConsent();
        [$plain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        for ($i = 0; $i < config('consent.max_confirm_attempts'); $i++) {
            $this->assertNull(ConsentCode::redeem($consent, 'WRONG1', ConsentCode::PURPOSE_CONFIRM));
        }

        $this->assertNull(
            ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM),
            'The correct code must stop working once the attempt budget is spent'
        );
    }

    public function test_correct_code_on_the_final_allowed_attempt_still_succeeds(): void
    {
        $consent = $this->pendingConsent();
        [$plain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $maxAttempts = (int) config('consent.max_confirm_attempts');

        // Spend every attempt but the last one on wrong guesses.
        for ($i = 0; $i < $maxAttempts - 1; $i++) {
            $this->assertNull(ConsentCode::redeem($consent, 'WRONG1', ConsentCode::PURPOSE_CONFIRM));
        }

        // The correct code, submitted as the very last attempt in the budget,
        // must still be accepted.
        $this->assertNotNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    public function test_expired_code_cannot_be_redeemed(): void
    {
        $consent = $this->pendingConsent();
        [$plain, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        $row->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    public function test_successful_redeem_marks_the_code_used(): void
    {
        $consent = $this->pendingConsent();
        [$plain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $redeemed = ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM);

        $this->assertNotNull($redeemed);
        $this->assertNotNull($redeemed->used_at);
        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM), 'single use');
    }

    public function test_redeem_cannot_succeed_twice_sequentially(): void
    {
        $consent = $this->pendingConsent();
        [$plain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $this->assertNotNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    /**
     * The sequential double-redeem test above would also pass against the old
     * buggy code, because by the time the second call runs, the first call's
     * write has already landed and the initial SELECT filters it out — that
     * proves nothing about the race. This test instead loads two model
     * instances of the *same still-live row* before either one claims it,
     * mirroring what two concurrent requests would each hold in memory, and
     * then performs the exact conditional UPDATE redeem() uses to claim a
     * row. Only one of the two may succeed.
     *
     * This is a statement about the SQL pattern being exclusive, not proof
     * that redeem() itself uses it — see
     * test_redeem_rejects_a_correct_code_stolen_by_a_concurrent_claim below
     * for that.
     */
    public function test_conditional_claim_update_is_exclusive(): void
    {
        $consent = $this->pendingConsent();
        [, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $a = ConsentCode::find($row->id);
        $b = ConsentCode::find($row->id);

        $claimedByA = ConsentCode::whereKey($a->id)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['used_at' => now()]);

        $claimedByB = ConsentCode::whereKey($b->id)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['used_at' => now()]);

        $this->assertSame(1, $claimedByA);
        $this->assertSame(0, $claimedByB);
    }

    /**
     * Proves redeem() itself — not just the SQL pattern — is exclusive under
     * a race. A DB::listen hook fires a competing claim on the same row the
     * instant redeem()'s own attempts UPDATE runs, i.e. after it has already
     * matched the hash but before it reaches its own claim UPDATE. If
     * redeem() used forceFill()->save() instead of the conditional UPDATE,
     * this competing claim would be invisible to it and it would return the
     * row anyway.
     */
    public function test_redeem_rejects_a_correct_code_stolen_by_a_concurrent_claim(): void
    {
        $consent = $this->pendingConsent();
        [$plain, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $fired = false;
        DB::listen(function ($query) use ($row, &$fired) {
            if (! $fired && str_contains($query->sql, 'attempts')) {
                $fired = true;
                DB::table('consent_codes')->where('id', $row->id)->update(['used_at' => now()]);
            }
        });

        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    /**
     * Proves the conditional increment — not just refresh()'s presence — is
     * what stops the budget being exceeded. Forces attempts to the cap
     * directly (as a racing burst of concurrent attempts could) without
     * invalidated_at being set, then confirms redeem() still refuses and does
     * not push attempts past the cap. This also exercises the only branch of
     * redeem() the rest of the suite left untested: $burned === 0 reached via
     * the budget guard rather than via used_at/invalidated_at.
     */
    public function test_redeem_refuses_when_budget_is_spent_but_row_is_still_live(): void
    {
        $consent = $this->pendingConsent();
        [$plain, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        $max = (int) config('consent.max_confirm_attempts');

        // A racing burst can drive attempts to the cap without invalidated_at
        // being set; the conditional increment is the only thing that stops it.
        $row->forceFill(['attempts' => $max])->save();

        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
        $this->assertSame($max, $row->fresh()->attempts, 'a refused attempt must not burn budget past the cap');
    }

    public function test_issue_uses_the_correct_ttl_and_secret_shape_per_purpose(): void
    {
        $consent = $this->pendingConsent();

        [$confirmPlain, $confirmRow] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        [$managePlain, $manageRow] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE);

        // The 6-char code and the 64-char hex token must not be confused with
        // each other — swapping the TTLs (or the generators) is a security
        // regression that a shape-blind test would miss.
        $this->assertSame(6, strlen($confirmPlain));
        $this->assertSame(64, strlen($managePlain));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $managePlain);

        $this->assertEqualsWithDelta(
            now()->addHours((int) config('consent.confirm_code_ttl_hours'))->getTimestamp(),
            $confirmRow->expires_at->getTimestamp(),
            2
        );

        $this->assertEqualsWithDelta(
            now()->addHours((int) config('consent.manage_token_ttl_hours'))->getTimestamp(),
            $manageRow->expires_at->getTimestamp(),
            2
        );
    }

    public function test_find_live_manage_token_lifecycle(): void
    {
        $consent = $this->pendingConsent();
        [$plain, $row] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE);

        $found = ConsentCode::findLiveManageToken($plain);
        $this->assertNotNull($found);
        $this->assertSame($row->id, $found->id);

        $row->forceFill(['invalidated_at' => now()])->save();
        $this->assertNull(ConsentCode::findLiveManageToken($plain), 'invalidated token must not be found');

        [$expiredPlain, $expiredRow] = ConsentCode::issue($consent, ConsentCode::PURPOSE_MANAGE);
        $expiredRow->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull(ConsentCode::findLiveManageToken($expiredPlain), 'expired token must not be found');

        [$confirmPlain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);
        $this->assertNull(ConsentCode::findLiveManageToken($confirmPlain), 'a confirm code is not a manage token');
    }

    public function test_code_cannot_be_redeemed_against_a_different_consent(): void
    {
        // Same guardian, two consents — e.g. a revoke followed by a
        // re-request. Two different guardians would never share a code in
        // the first place, so that case proves nothing about scoping by
        // consent_id specifically.
        $guardian = Guardian::create(['email' => Str::uuid().'@example.com', 'locale' => 'cs']);
        $consentA = $this->pendingConsent($guardian);
        $consentB = $this->pendingConsent($guardian);

        [$plain] = ConsentCode::issue($consentA, ConsentCode::PURPOSE_CONFIRM);

        $this->assertNull(ConsentCode::redeem($consentB, $plain, ConsentCode::PURPOSE_CONFIRM));
        $this->assertNotNull(ConsentCode::redeem($consentA, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    public function test_code_cannot_be_redeemed_with_the_wrong_purpose(): void
    {
        $consent = $this->pendingConsent();
        [$plain] = ConsentCode::issue($consent, ConsentCode::PURPOSE_CONFIRM);

        $this->assertNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_MANAGE));
        $this->assertNotNull(ConsentCode::redeem($consent, $plain, ConsentCode::PURPOSE_CONFIRM));
    }

    public function test_redeem_normalizes_case_whitespace_and_confusables(): void
    {
        // Crockford Base32: I/L fold onto 1, O folds onto 0. This is the
        // entire point of using that alphabet for a code read aloud over the
        // phone, so it needs to actually be exercised end to end.
        $canonical = 'AB10CD';
        $hash = StudentCodes::hash($canonical);

        $variants = [
            'lowercase' => strtolower($canonical),
            'surrounding whitespace' => "  {$canonical}  ",
            'confusables (I for 1, O for 0)' => 'ABIOCD',
        ];

        foreach ($variants as $label => $typed) {
            $consent = $this->pendingConsent();
            ConsentCode::create([
                'consent_id' => $consent->id,
                'purpose' => ConsentCode::PURPOSE_CONFIRM,
                'code_hash' => $hash,
                'expires_at' => now()->addHours(1),
            ]);

            $redeemed = ConsentCode::redeem($consent, $typed, ConsentCode::PURPOSE_CONFIRM);

            $this->assertNotNull($redeemed, "Failed to redeem variant: {$label}");
        }
    }

    public function test_issue_rejects_an_unknown_purpose(): void
    {
        $consent = $this->pendingConsent();

        $this->expectException(InvalidArgumentException::class);
        ConsentCode::issue($consent, 'bogus');
    }

    public function test_redeem_rejects_an_unknown_purpose(): void
    {
        $consent = $this->pendingConsent();

        $this->expectException(InvalidArgumentException::class);
        ConsentCode::redeem($consent, 'ABCDEF', 'bogus');
    }
}
