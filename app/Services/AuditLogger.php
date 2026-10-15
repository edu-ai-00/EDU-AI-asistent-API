<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one way anything writes to the unified AUM audit log (BR-BNNASY).
 *
 * Callers say what happened; who did it, in which role, through which flow
 * and from where is read off the current request, so every call site records
 * those the same way:
 *
 *   - actor: the authenticated user; none for an X-Admin-Key call, a guardian
 *     on a consent link, or a scheduled command. A caller that knows who acted
 *     on a request that is not signed in yet (accepting an invite) names them.
 *   - actor_role: the user's role, `api_key` for X-Admin-Key, `system` when
 *     there is no HTTP request at all; a caller may name it (`guardian`).
 *   - source: `admin` when the admin middleware let the request in (the
 *     school/teacher flow), `system` with no request, otherwise
 *     `self_service` (a student or guardian acting for themselves).
 *
 * Call it inside the same transaction as the change it describes where there
 * is one, so a rolled-back action leaves no row claiming it happened.
 *
 * Never put a code, a token or any other plaintext secret into $meta — the
 * log is read and exported by staff. Attribute diffs are redacted
 * automatically for the fields in REDACTED_FIELDS.
 */
class AuditLogger
{
    public const REDACTED_VALUE = '[skryto]';

    /** Model attributes whose values never reach the log. */
    private const REDACTED_FIELDS = [
        'password',
        'remember_token',
        'login_code',
        'login_code_hash',
        'join_code',
        'token',
    ];

    /** Bookkeeping columns that change on every save and say nothing. */
    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * @param  Model|null  $target  what the action was done to; type and id
     *                              are read off it (a deleted model still
     *                              has its key)
     * @param  array<string, array{from: mixed, to: mixed}>  $diff
     * @param  array<string, mixed>  $meta  extra context — never secrets
     * @param  array{school_id?: ?int, classroom_id?: ?int}  $scope  overrides
     *         what is derived from a Classroom or School target
     * @param  User|null  $actor  who acted, when the request cannot say —
     *                            null reads the authenticated user off it
     * @param  Request|false|null  $request  the request the action came in
     *                              on; null for the current one, false for
     *                              none at all (a scheduled command)
     * @param  bool  $recordDevice  false to leave ip/user_agent out (e.g. a
     *                              mirror whose source row already has them)
     */
    public function log(
        string $action,
        ?Model $target = null,
        array $diff = [],
        array $meta = [],
        array $scope = [],
        string $outcome = AuditLog::OUTCOME_SUCCESS,
        ?User $actor = null,
        ?string $actorRole = null,
        Request|false|null $request = null,
        bool $recordDevice = true,
    ): AuditLog {
        $request ??= app('request');

        // A request that never went through the router is the blank one the
        // container hands out to artisan commands and queued jobs.
        $isHttp = $request !== false && $request->route() !== null;
        $user = $actor ?? ($isHttp ? $request->user() : null);
        $adminRole = $isHttp ? $request->attributes->get('admin_role') : null;

        if ($actorRole === null) {
            $actorRole = match (true) {
                $user !== null => $user->role ?? 'student',
                $adminRole !== null => AuditLog::ROLE_API_KEY,
                ! $isHttp => AuditLog::ROLE_SYSTEM,
                default => null,
            };
        }

        $source = match (true) {
            $adminRole !== null => AuditLog::SOURCE_ADMIN,
            ! $isHttp => AuditLog::SOURCE_SYSTEM,
            default => AuditLog::SOURCE_SELF_SERVICE,
        };

        $scope += $this->scopeOf($target);

        return AuditLog::create([
            'actor_id' => $user?->id,
            'actor_role' => $actorRole,
            'action' => $action,
            'outcome' => $outcome,
            'target_type' => $target ? Str::snake(class_basename($target)) : null,
            'target_id' => $target?->getKey(),
            'school_id' => $scope['school_id'] ?? null,
            'classroom_id' => $scope['classroom_id'] ?? null,
            'source' => $source,
            'diff' => $diff ?: null,
            // Capped so a future caller cannot dump an entire request into it.
            'meta' => array_slice($meta, 0, 30) ?: null,
            'ip' => $recordDevice && $isHttp ? $request->ip() : null,
            'user_agent' => $recordDevice && $isHttp ? UserAgent::sanitize($request->userAgent()) : null,
        ]);
    }

    /**
     * Record the edit the user's last save() made. A role change is its own
     * event — it is the one an auditor looks for — so it is split off from
     * the plain name/email edit around it. Nothing changed, nothing logged.
     *
     * @param  array<string, mixed>  $meta
     */
    public function userUpdated(User $user, array $meta = []): void
    {
        $diff = self::changes($user);

        if (isset($diff['role'])) {
            $this->log('user.role_changed', $user, diff: ['role' => $diff['role']], meta: $meta);
            unset($diff['role']);
        }

        if ($diff) {
            $this->log('user.updated', $user, diff: $diff, meta: $meta);
        }
    }

    /**
     * The attributes the model's last save() changed, as {from, to} pairs.
     * Call right after update()/save() — it reads getChanges()/getPrevious().
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changes(Model $model): array
    {
        $previous = $model->getPrevious();
        $diff = [];

        foreach ($model->getChanges() as $field => $to) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $diff[$field] = self::pair($field, $previous[$field] ?? null, $to);
        }

        return $diff;
    }

    /**
     * {from: null, to: value} pairs for a model that was just created, so a
     * creation reads the same way as an edit in the log. Fields left empty
     * are skipped — a null → null entry says nothing.
     *
     * @param  array<int, string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function created(Model $model, array $fields): array
    {
        $diff = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);

            if ($value !== null) {
                $diff[$field] = self::pair($field, null, $value);
            }
        }

        return $diff;
    }

    /**
     * A {from, to} entry for one field, redacted when the field is secret.
     * Null stays null either way, so the log still says whether e.g. a class
     * code was switched on or off without saying what it is.
     *
     * @return array{from: mixed, to: mixed}
     */
    public static function pair(string $field, mixed $from, mixed $to): array
    {
        if (in_array($field, self::REDACTED_FIELDS, true)) {
            $from = $from === null ? null : self::REDACTED_VALUE;
            $to = $to === null ? null : self::REDACTED_VALUE;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * A {from, to} entry for a set of ids (a roster, a membership), or null
     * when the set did not change.
     *
     * @param  iterable<int>  $before
     * @param  iterable<int>  $after
     * @return array{from: array<int>, to: array<int>}|null
     */
    public static function idSetChange(iterable $before, iterable $after): ?array
    {
        $from = collect($before)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $to = collect($after)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $from === $to ? null : ['from' => $from, 'to' => $to];
    }

    /** @return array{school_id?: ?int, classroom_id?: ?int} */
    private function scopeOf(?Model $target): array
    {
        return match (true) {
            $target instanceof Classroom => [
                'classroom_id' => $target->getKey(),
                'school_id' => $target->school_id,
            ],
            $target instanceof School => ['school_id' => $target->getKey()],
            default => [],
        };
    }
}
