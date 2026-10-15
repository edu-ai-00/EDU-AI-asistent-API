<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Exceptions\AccountDeactivatedException;
use App\Services\AuditLogger;
use App\Support\StudentCodes;
use DateTimeInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

class User extends Authenticatable
{
    use HasApiTokens {
        createToken as private issueSanctumToken;
    }
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'login_code',
        'password',
        'email_verified_at',
        'avatar_index',
        'selected_subjects',
        'onboarded_at',
        'is_guest',
        'device_id',
        'is_minor',
        'login_code_hash',
        'guardian_id',
        'merged_into_user_id',
        'merged_at',
        'invited_at',
        'invite_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
            'selected_subjects' => 'array',
            'is_guest' => 'boolean',
            'is_minor' => 'boolean',
            'merged_at' => 'datetime',
            'merged_into_user_id' => 'integer',
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Identity for a coded login lives in one column, login_code_hash — see
     * CodeController::resolve() (BR-8FDF37). login_code (plaintext) exists
     * only so a teacher can re-read a school student's code in admin; it is
     * never itself the lookup key. Keeping the hash derived here, on every
     * write path (factories, seeders, tests, and any future caller), rather
     * than requiring each caller to remember to set both columns, is what
     * makes "one identity, one column" actually hold — a caller that only
     * sets login_code and forgets the hash would otherwise produce an
     * account no one can log into.
     *
     * Two rules, in order:
     *  1. An explicit write to login_code_hash in the same save wins and
     *     this hook does nothing — but ONLY when that explicit hash
     *     actually equals hash(login_code). AdminClassroomController sets
     *     both columns explicitly when it mints a school code, and without
     *     this exception the hook would silently recompute the same value
     *     a moment later, making that explicit write look load-bearing
     *     when it is actually dead code. Equality, not merely "was it
     *     touched", is what makes a mismatched pair impossible: naively
     *     standing down for ANY explicit hash write would let
     *     User::create(['login_code' => 'JK56MN', 'login_code_hash' =>
     *     hash('PQ78RS')]) produce an account that authenticates under a
     *     code the teacher can never read and fails under the one they
     *     can — nothing reaches that today (every write path assigns a
     *     matching pair explicitly), but the hook is the one place whose
     *     entire job is making that structurally impossible, not merely
     *     unreached (BR-8FDF37 I4).
     *  2. Otherwise (no explicit hash write, or one that didn't match),
     *     login_code_hash tracks login_code exactly, INCLUDING null:
     *     nulling login_code without also nulling the hash would leave a
     *     deleted code still authenticating — a credential that outlived
     *     its own deletion (BR-8FDF37 I3). No caller nulls login_code
     *     today, but the hook's job is to make that safe whenever one does.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('login_code_hash')) {
                $expectedHash = $user->login_code === null
                    ? null
                    : StudentCodes::hash($user->login_code);

                if ($user->login_code_hash === $expectedHash) {
                    return;
                }
            }

            if ($user->isDirty('login_code')) {
                $user->login_code_hash = $user->login_code === null
                    ? null
                    : StudentCodes::hash($user->login_code);
            }
        });

        // Joining the team starts from a clean invite state (BR-XW6CWV). An
        // invite left over from an earlier stint on the team would otherwise
        // come back with its old expiry when someone rejoins by a path that is
        // not an invite — the user detail role select — and lock them out. The
        // invite itself (AdminTeamController::store) writes fresh values right
        // after the promotion, so it is unaffected. Leaving the team clears
        // nothing: on a student the fields are inert (teamStatus() is null and
        // the admin login refuses on role first), and not touching them keeps
        // a removal a pure role change in the audit log.
        static::saving(function (User $user) {
            $joiningTeam = $user->isDirty('role')
                && $user->isAdminOrTeacher()
                && !in_array($user->getOriginal('role'), ['admin', 'teacher'], true);

            if ($joiningTeam && !$user->isDirty('invite_expires_at')) {
                $user->invited_at = null;
                $user->invite_expires_at = null;
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Onboarding
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Whether this account has already been through the sign-up onboarding —
     * nickname, colour profile, avatar vibe (BR-YXVMKY).
     *
     * The one question every login path asks before deciding whether to show
     * it. A guest is never onboarded and never asked: the onboarding belongs
     * to joining, and a course PIN is not joining — it must land the student
     * in the course, not in a profile form.
     */
    public function hasOnboarded(): bool
    {
        if ($this->is_guest) {
            return true;
        }

        return $this->onboarded_at !== null;
    }

    /**
     * Record that the account finished the onboarding. Idempotent: the
     * timestamp is when they first joined, so a replayed sync-queue entry
     * (the client queues this like any other profile write, so it survives
     * being offline) must not move it.
     */
    public function markOnboarded(): void
    {
        if ($this->onboarded_at !== null) {
            return;
        }

        $this->forceFill(['onboarded_at' => now()])->save();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Deactivation (BR-Q3ZJ6Q)
    // ═══════════════════════════════════════════════════════════════════════════

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * The one answer to "may this account open a session?".
     *
     * createToken() below asks it, and every login path — password, e-mail
     * code, student login code, Google/Apple/Microsoft, guest reconnect, the
     * admin panel, both refresh endpoints — ends in createToken(), so none of
     * them can forget. Paths that write something before minting the token
     * (fold a guest in, stamp email_verified_at, update the profile) also
     * call it up front, so a deactivated account is refused before anything
     * lands on it.
     *
     * @throws AccountDeactivatedException
     */
    public function ensureCanSignIn(): void
    {
        if ($this->isDeactivated()) {
            throw new AccountDeactivatedException();
        }
    }

    /**
     * Sanctum's createToken(), refused for a deactivated account.
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $this->ensureCanSignIn();

        return $this->issueSanctumToken($name, $abilities, $expiresAt);
    }

    /**
     * Switch the account off: no new logins, and every token it holds is
     * revoked in the same transaction so the next API call it makes is a 401.
     * Nothing else is touched — classes, progress and stats stay exactly as
     * they were, which is what makes reactivate() a full undo.
     *
     * Returns false (and records nothing) when it was already deactivated.
     */
    public function deactivate(?User $performedBy, ?Request $request = null): bool
    {
        if ($this->isDeactivated()) {
            return false;
        }

        DB::transaction(function () use ($performedBy, $request) {
            $this->forceFill(['deactivated_at' => now()])->save();
            $this->tokens()->delete();
            UserAccountEvent::record($this, UserAccountEvent::DEACTIVATED, $performedBy, $request);
        });

        return true;
    }

    /**
     * Let the account sign in again. Old tokens stay revoked — the person
     * logs in afresh. Returns false when it was not deactivated.
     */
    public function reactivate(?User $performedBy, ?Request $request = null): bool
    {
        if (! $this->isDeactivated()) {
            return false;
        }

        DB::transaction(function () use ($performedBy, $request) {
            $this->forceFill(['deactivated_at' => null])->save();
            UserAccountEvent::record($this, UserAccountEvent::REACTIVATED, $performedBy, $request);
        });

        return true;
    }

    public function accountEvents(): HasMany
    {
        return $this->hasMany(UserAccountEvent::class);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function courses(): HasMany
    {
        return $this->hasMany(UserCourse::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(UserStats::class);
    }

    /**
     * Classrooms this student is a member of (BR-3V2P22). A student may sit
     * in several — maths with one teacher, physics with another — so the
     * old users.classroom_id column is gone and membership lives on the
     * classroom_student pivot; its created_at is the moment they joined.
     */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_student', 'user_id', 'classroom_id')
            ->withTimestamps();
    }

    /**
     * Users who are a member of ANY of the given classrooms.
     *
     * The one place "belongs to one of these classes" is spelled: every
     * teacher-scoped listing (users, chats, activity) and every classroom
     * filter goes through here, so none of them can drift back to a
     * single-classroom reading.
     *
     * @param  iterable<int>|\Illuminate\Support\Collection<int, int>  $classroomIds
     */
    public function scopeInClassrooms($query, iterable $classroomIds)
    {
        $ids = collect($classroomIds)->map(fn ($id) => (int) $id)->all();

        return $query->whereIn('users.id', function ($sub) use ($ids) {
            $sub->select('user_id')
                ->from('classroom_student')
                ->whereIn('classroom_id', $ids);
        });
    }

    /** Comma-joined classroom names for admin badges; '' when in none. */
    public function classroomNames(): string
    {
        return $this->classrooms->pluck('name')->implode(', ');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(UserTheme::class);
    }

    /**
     * The target user this account was merged into, if any.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_user_id');
    }

    /**
     * True if this profile has been soft-merged into another account.
     * Login flows must reject merged users with HTTP 410.
     */
    public function isMerged(): bool
    {
        return $this->merged_into_user_id !== null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Role helpers
    // ═══════════════════════════════════════════════════════════════════════════

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isAdminOrTeacher(): bool
    {
        return in_array($this->role, ['admin', 'teacher']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Team account lifecycle (BR-XW6CWV)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Where a team account stands: `invited` (invite pending), `expired`
     * (invite pending but past its date — the admin has to resend it),
     * `active`, or `deactivated` (BR-Q3ZJ6Q's account switch, which wins over
     * any invite state). Null for anyone who is not on the team.
     *
     * Derived from timestamps rather than stored, so accounts that predate the
     * lifecycle — nothing set — are simply `active`.
     */
    public function teamStatus(): ?string
    {
        if (!$this->isAdminOrTeacher()) {
            return null;
        }

        if ($this->isDeactivated()) {
            return 'deactivated';
        }

        if ($this->invite_expires_at !== null) {
            return $this->hasExpiredInvite() ? 'expired' : 'invited';
        }

        return 'active';
    }

    public function hasExpiredInvite(): bool
    {
        return $this->invite_expires_at !== null && $this->invite_expires_at->isPast();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Ownership relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function teachingClassrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_teacher', 'teacher_id', 'classroom_id')
            ->withTimestamps();
    }

    /**
     * The schools this teacher has been assigned to by an admin (BR-8QAWKP).
     *
     * Deliberately not consulted by teachesStudent() or TeacherCourseAccess:
     * a shared school buys visibility of a colleague's class in the list, not
     * access to anything inside it.
     */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_teacher', 'teacher_id', 'school_id')
            ->withTimestamps();
    }

    /**
     * Replace this team member's schools from the teacher's side (BR-R84FVD).
     *
     * The same pivot AdminSchoolController writes from the school's side, and
     * held to the same rule: only admin and teacher accounts are ever filed
     * under a school, so for anyone else this is a no-op. Returns the id-set
     * change for the audit log, or null when nothing moved.
     */
    public function syncSchools(array $schoolIds): ?array
    {
        if (!$this->isAdminOrTeacher()) {
            return null;
        }

        $before = $this->schools()->pluck('schools.id');
        $this->schools()->sync($schoolIds);

        return AuditLogger::idSetChange($before, $this->schools()->pluck('schools.id'));
    }

    /**
     * Replace the classes this team member teaches, from the person's side
     * (BR-QKCBCT). The same classroom_teacher pivot the class dialog's teacher
     * roster writes, so both sides agree. Only admin and teacher accounts
     * teach — for anyone else this is a no-op, and a student's own classes
     * stay on classroom_student. Returns the id-set change for the audit log,
     * or null when nothing moved.
     */
    public function syncTeachingClassrooms(array $classroomIds): ?array
    {
        if (!$this->isAdminOrTeacher()) {
            return null;
        }

        $before = $this->teachingClassrooms()->pluck('classrooms.id');
        $this->teachingClassrooms()->sync($classroomIds);

        return AuditLogger::idSetChange($before, $this->teachingClassrooms()->pluck('classrooms.id'));
    }

    /**
     * Does this teacher have the given student in one of their classrooms?
     *
     * The single place that question is answered — controllers and the
     * teacher.student middleware both go through here so a teacher cannot
     * read one student's data from an endpoint that forgot to scope.
     */
    public function teachesStudent(int $studentId): bool
    {
        $taught = $this->teachingClassrooms()->pluck('classrooms.id');

        if ($taught->isEmpty()) {
            return false;
        }

        return self::whereKey($studentId)
            ->inClassrooms($taught)
            ->exists();
    }

    public function createdCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'created_by');
    }
}
