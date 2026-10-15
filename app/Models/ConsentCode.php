<?php

namespace App\Models;

use App\Support\StudentCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One-time secrets attached to a consent: the 6-char code the parent types,
 * and the magic-link token in every guardian email. Only hashes are stored.
 */
class ConsentCode extends Model
{
    public const PURPOSE_CONFIRM = 'confirm';
    public const PURPOSE_MANAGE = 'manage';

    protected $fillable = [
        'consent_id',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'used_at',
        'invalidated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /**
     * Issue a secret for the given purpose. By default this invalidates any
     * live predecessor in the same transaction as the insert — the original,
     * still-default behaviour requestConsent() and confirm() both rely on.
     *
     * Pass $invalidatePredecessors: false to skip that step and invalidate
     * separately later (see invalidateOthers()) — the caller then owns the
     * window between "new secret exists" and "old secret is dead", which
     * matters when there is a fallible step (e.g. an email send) in between
     * that the caller wants to keep out of the transaction. See
     * ConsentService::sendManageLink() for why: wrapping issue() and a
     * Mail::send() in one transaction would hold a `consents` row lock open
     * across an SMTP call, and unconditionally invalidating before the send
     * would strip a guardian of their only working manage link if the send
     * then failed.
     *
     * Returns [plaintext, row] — the plaintext exists only in this return
     * value and in the email that follows; it is never persisted.
     *
     * @return array{0: string, 1: self}
     */
    public static function issue(Consent $consent, string $purpose, bool $invalidatePredecessors = true): array
    {
        self::assertValidPurpose($purpose);

        return DB::transaction(function () use ($consent, $purpose, $invalidatePredecessors) {
            // Serialise concurrent issue() calls for the same consent so two
            // simultaneous requests (e.g. a guardian double-clicking "send me
            // a new link") cannot both pass the "invalidate predecessors"
            // step without seeing each other's new row, which would leave
            // two live codes/tokens alive at once. No-op on sqlite — this
            // path is therefore not exercised by the test suite; it only
            // takes effect on MySQL/Postgres in real deployments.
            Consent::whereKey($consent->id)->lockForUpdate()->first();

            $plain = $purpose === self::PURPOSE_MANAGE
                ? StudentCodes::generateToken()
                : StudentCodes::generate();

            $ttlHours = $purpose === self::PURPOSE_MANAGE
                ? (int) config('consent.manage_token_ttl_hours')
                : (int) config('consent.confirm_code_ttl_hours');

            // Create the new secret first: if this insert fails, the consent
            // still has whatever live code it had before — never zero.
            $row = static::create([
                'consent_id' => $consent->id,
                'purpose' => $purpose,
                'code_hash' => StudentCodes::hash($plain),
                'expires_at' => now()->addHours($ttlHours),
            ]);

            if ($invalidatePredecessors) {
                self::invalidateOthers($consent, $purpose, $row);
            }

            return [$plain, $row];
        });
    }

    /**
     * Invalidate every other live code/token for this consent and purpose,
     * excluding $keep — including ones already used. A manage token that
     * ever picks up used_at must not stay eligible for findLiveManageToken()
     * for the rest of its TTL just because this query used to skip used
     * rows.
     *
     * Split out of issue() so a caller using
     * issue(..., invalidatePredecessors: false) can defer this until after
     * some later step succeeds — see that parameter's docblock.
     */
    public static function invalidateOthers(Consent $consent, string $purpose, self $keep): void
    {
        static::where('consent_id', $consent->id)
            ->where('purpose', $purpose)
            ->where('id', '!=', $keep->id)
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    /**
     * Verify a typed code against the live row for this consent. Every attempt
     * — right or wrong — burns one of the attempt budget; once the budget is
     * spent the row is invalidated and even the correct code stops working.
     */
    public static function redeem(Consent $consent, string $plain, string $purpose): ?self
    {
        self::assertValidPurpose($purpose);

        $row = static::where('consent_id', $consent->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $row || $row->expires_at->isPast()) {
            return null;
        }

        $maxAttempts = (int) config('consent.max_confirm_attempts');

        // Atomically burn one attempt, but only if the budget is not already
        // spent. A conditional UPDATE — not increment()+refresh() on the
        // in-memory model — is what makes this safe when two requests race on
        // the same row: the WHERE clause is evaluated by the database against
        // the current committed value, not a value read moments earlier here.
        $burned = static::whereKey($row->id)
            ->where('attempts', '<', $maxAttempts)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->increment('attempts');

        if ($burned === 0) {
            return null; // budget already spent
        }

        $row->refresh();

        if (! hash_equals($row->code_hash, StudentCodes::hash($plain))) {
            if ($row->attempts >= $maxAttempts) {
                $row->forceFill(['invalidated_at' => now()])->save();
            }

            return null;
        }

        // Claim the row atomically: only the request whose UPDATE actually
        // flips used_at from null wins. A second request racing with the
        // correct code loses here even though it also matched the hash.
        $now = now();
        $claimed = static::whereKey($row->id)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['used_at' => $now]);

        if ($claimed === 0) {
            return null; // someone else redeemed it first
        }

        $row->forceFill(['used_at' => $now]);

        return $row;
    }

    /**
     * Look a magic-link token up without knowing the consent up front. Manage
     * tokens stay usable for their whole TTL — the management page needs more
     * than one action per visit.
     */
    public static function findLiveManageToken(string $plain): ?self
    {
        return static::where('purpose', self::PURPOSE_MANAGE)
            ->where('code_hash', StudentCodes::hash($plain))
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private static function assertValidPurpose(string $purpose): void
    {
        if (! in_array($purpose, [self::PURPOSE_CONFIRM, self::PURPOSE_MANAGE], true)) {
            throw new InvalidArgumentException("Unknown consent code purpose: {$purpose}");
        }
    }
}
