<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the unified Advanced User Management audit trail (BR-BNNASY).
 *
 * Written only through App\Services\AuditLogger. Append-only: the model
 * refuses to update or delete a row. The one way rows ever leave is the
 * retention prune (MassPrunable issues a query-builder delete, so it does not
 * pass through the guard below — that is the intended exception).
 */
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_DENIED = 'denied';

    /** An admin or teacher acting through the admin panel (school flow). */
    public const SOURCE_ADMIN = 'admin';
    /** A student or guardian acting on their own behalf in the app. */
    public const SOURCE_SELF_SERVICE = 'self_service';
    /** A scheduled command or other job with no request behind it. */
    public const SOURCE_SYSTEM = 'system';

    public const ROLE_GUARDIAN = 'guardian';
    public const ROLE_API_KEY = 'api_key';
    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'outcome',
        'target_type',
        'target_id',
        'school_id',
        'classroom_id',
        'source',
        'diff',
        'meta',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit log rows are append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log rows are append-only.');
        });
    }

    /**
     * No foreign key behind actor_id (see the migration), so this resolves to
     * null once the account is gone — the id itself stays on the row.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days');

        // 0 (or less) means keep forever: match nothing.
        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
