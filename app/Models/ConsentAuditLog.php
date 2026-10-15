<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Append-only trail behind every consent event. Rows outlive the consent they
 * describe (nullOnDelete) so a revocation stays provable after the erasure.
 */
class ConsentAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'consent_id',
        'guardian_id',
        'event',
        'ip',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /**
     * Record an event. Never pass a code, a token, or any other plaintext
     * secret in $meta — this table is read by support staff. A pseudonymous
     * internal id (e.g. a child's users.id on an account with no personal
     * data of its own) is fine to include.
     *
     * $consent is nullable on purpose: ConsentService::confirm() calls this
     * with a null consent for a 'failed_attempt' against a public_id that
     * doesn't resolve to any row — an unauthenticated guess, not a known
     * guardian's action. That row is written with both consent_id and
     * guardian_id null, and its ip/user_agent are real. BR-8FDF37 Task 10's
     * purge scrubs ip/user_agent by consent_id, so a null-consent row can
     * never be found and scrubbed by that or any future consent_id-scoped
     * cleanup — its IP is retained indefinitely. That is accepted here as
     * the cost of keeping a security-relevant signal (who tried and failed
     * to guess a code) rather than tightening this parameter to
     * non-nullable; if that trade-off ever changes, this is the line to
     * revisit.
     */
    public static function record(
        ?Consent $consent,
        string $event,
        ?Request $request = null,
        array $meta = [],
    ): self {
        $row = static::create([
            'consent_id' => $consent?->id,
            'guardian_id' => $consent?->guardian_id,
            'event' => $event,
            'ip' => $request?->ip(),
            'user_agent' => UserAgent::sanitize($request?->userAgent()),
            // Capped so a future caller cannot dump an entire request into it.
            'meta' => array_slice($meta, 0, 20) ?: null,
        ]);

        self::mirrorToAuditLog($row, $consent, $request);

        return $row;
    }

    /**
     * Consent and child-onboarding events are part of the unified AUM audit
     * log (BR-BNNASY), not a separate mechanism beside it: every event lands
     * there too, as `consent.<event>`, inside whatever transaction this row
     * was written in.
     *
     * This table stays the evidentiary record — kept forever (decision 9),
     * with the guardian's ip/user_agent scrubbed by consent:purge-revoked.
     * The mirror therefore carries no ip/user_agent of its own and points
     * back here instead, so the purge never has a second copy of that
     * personal data to chase, and the audit log's retention can never erase
     * proof of a consent or its revocation.
     */
    private static function mirrorToAuditLog(self $row, ?Consent $consent, ?Request $request): void
    {
        // Nobody signs in on a consent link: without a user behind the
        // request it is the guardian's own flow. An admin revoking a family
        // class from the panel is a signed-in user and is recorded as such.
        // No request at all is consent:purge-revoked — a system event.
        $guardianActing = $request !== null
            && $request->user() === null
            && $consent?->guardian_id !== null;

        app(AuditLogger::class)->log(
            action: 'consent.' . $row->event,
            target: $consent,
            meta: ['consent_audit_log_id' => $row->id, 'guardian_id' => $row->guardian_id] + ($row->meta ?? []),
            actorRole: $guardianActing ? AuditLog::ROLE_GUARDIAN : null,
            request: $request ?? false,
            recordDevice: false,
        );
    }
}
