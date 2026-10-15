<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Consent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'public_id',
        'guardian_id',
        'status',
        'text_version',
        'locale',
        'country',
        'confirmed_at',
        'revoked_at',
        'purge_after',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'purge_after' => 'datetime',
    ];

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(ConsentCode::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ConsentAuditLog::class);
    }

    /**
     * Open a fresh pending consent. The wording version is frozen onto the row
     * so a later change to the legal text cannot rewrite history.
     */
    public static function startFor(Guardian $guardian, string $locale, ?string $country): self
    {
        return static::create([
            // orderedUuid, not uuid: a random v4 scatters inserts across the
            // unique index on public_id and splits B-tree pages for no reason.
            'public_id' => (string) Str::orderedUuid(),
            'guardian_id' => $guardian->id,
            'status' => self::STATUS_PENDING,
            'text_version' => (string) config('consent.text_version'),
            'locale' => $locale,
            'country' => $country,
        ]);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * The consent that currently governs a guardian's children — the most
     * recent row that ever reached a governing state (confirmed, later
     * possibly revoked). A caller must check ->isConfirmed() on the result;
     * this only resolves *which* row is authoritative.
     *
     * Deliberately excludes PENDING/EXPIRED rows: a guardian who requests a
     * fresh code (lost the old one, e.g.) while their existing consent is
     * still confirmed would otherwise get a newer, not-yet-confirmed row
     * picked as "governing" and lock their already-confirmed children out
     * until that new request is itself confirmed or expires — a real
     * regression, not a hypothetical one. CONFIRMED/REVOKED are the only two
     * states a consent reaches by actually governing logins, so ordering by
     * id within just those two is what makes "most recent" mean "most
     * recently became governing" rather than "most recently requested."
     *
     * This exists because "does the guardian have ANY confirmed consent"
     * (the check this replaces in CodeController) is bypassable the moment
     * a guardian ever holds two confirmed rows at once — revoking one left
     * the other, unrevoked, still satisfying that query. ConsentService::
     * confirm() now supersedes any prior CONFIRMED row on a fresh
     * confirmation and revoke() revokes every CONFIRMED row for the
     * guardian, so that state should no longer arise going forward; this
     * method is the fail-closed backstop for data that predates those fixes
     * or for any future write path that doesn't go through the service
     * (BR-8FDF37 C1).
     *
     * Be honest about the direction this guards: it is fail-closed for
     * "the most recent row is REVOKED" only. An older-REVOKED /
     * newer-CONFIRMED pair (id order) resolves to the newer, CONFIRMED row
     * and returns a working login — which is correct once a guardian has
     * legitimately re-consented after an earlier revocation, but would be
     * wrong data if a REVOKED row somehow ever got a higher id than a
     * still-live CONFIRMED one. That specific ordering is not reachable
     * through ConsentService today (revoke() only ever flips existing rows
     * to REVOKED; it never creates a new, higher-id CONFIRMED one), but this
     * method does not independently verify it — it is not a universal
     * guarantee against every possible row arrangement, only against the
     * one this task's revocation-bypass fix needed to close.
     */
    public static function governingFor(int $guardianId): ?self
    {
        return static::where('guardian_id', $guardianId)
            ->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_REVOKED])
            ->latest('id')
            ->first();
    }
}
