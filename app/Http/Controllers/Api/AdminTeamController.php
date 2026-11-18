<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Mail\TeamInviteMail;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Admin and teacher accounts and their invite lifecycle: invited → active,
 * with an expiry and a resend (BR-XW6CWV). The state is derived on the model
 * — see User::teamStatus(). Switching an account off and on again is the
 * account-wide deactivate/reactivate on AdminUserController (BR-Q3ZJ6Q).
 */
class AdminTeamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List all admin and teacher accounts.
     *
     * GET /api/admin/team
     */
    public function index(): JsonResponse
    {
        $users = User::whereIn('role', ['admin', 'teacher'])
            ->with('schools:schools.id,name', 'teachingClassrooms:classrooms.id')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => $this->format($u)),
        ]);
    }

    /**
     * Invite an admin or teacher.
     *
     * An e-mail that already belongs to a student is not refused and does not
     * make a second account: that student account is promoted in place and
     * keeps its id and everything attached to it (48247f8). The audit row
     * records that it happened (`existing_account`). An admin invited as a
     * teacher is not refused either — see fileAdminAsTeacher() (BR-A7KEZ6) —
     * and neither is a teacher invited as an admin: see promoteTeacherToAdmin()
     * (BR-98XQ58). Anyone invited again in the role they already have is
     * refused — for them the answer is a resend, not a new invite.
     *
     * POST /api/admin/team
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'role' => 'required|in:admin,teacher',
            'school_ids' => 'sometimes|array',
            'school_ids.*' => 'integer|distinct|exists:schools,id',
        ]);

        $email = strtolower(trim($validated['email']));
        $existing = User::where('email', $email)->first();
        $schoolIds = $validated['school_ids'] ?? [];

        if ($existing?->isAdmin() && $validated['role'] === 'teacher') {
            return $this->fileAdminAsTeacher($existing, $schoolIds);
        }

        if ($existing?->isTeacher() && $validated['role'] === 'admin') {
            return $this->promoteTeacherToAdmin($existing);
        }

        if ($existing && in_array($existing->role, ['admin', 'teacher'])) {
            // The admin panel rewords this one per screen, keyed on the text
            // below — keep the two in step (BR-FJ8FR6).
            return response()->json([
                'message' => 'Tento uživatel už je členem týmu.',
            ], 422);
        }

        $user = DB::transaction(function () use ($existing, $validated, $email, $schoolIds) {
            if ($existing) {
                // Promote existing student to teacher/admin
                $existing->update([
                    'name' => $validated['name'],
                    'role' => $validated['role'],
                ]);
                $user = $existing;

                $this->audit->userUpdated($user);
            } else {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $email,
                    'role' => $validated['role'],
                    'email_verified_at' => now(),
                ]);
            }

            $this->openInvite($user);

            if ($schoolIds !== []) {
                $user->schools()->syncWithoutDetaching($schoolIds);
            }

            $this->audit->log('user.invited', $user, meta: [
                'role' => $user->role,
                'existing_account' => $existing !== null,
                'school_ids' => $schoolIds,
                'invite_expires_at' => $user->invite_expires_at->toIso8601String(),
            ]);

            return $user;
        });

        $this->sendInvite($user);

        return response()->json([
            'data' => $this->format($user->load('schools:schools.id,name', 'teachingClassrooms:classrooms.id')),
        ], 201);
    }

    /**
     * An admin invited as a teacher (BR-A7KEZ6).
     *
     * An account has a single role, and an admin already has everything a
     * teacher has — classes of their own, every school's classes. Refusing
     * left no way to put an admin on the Učitelé tab, and demoting would take
     * the panel away from them (the reporter was inviting their own, only,
     * admin account). So the role, the name and the invite state stay as they
     * are, the picked schools are added to the ones the admin already has,
     * and no invite goes out: this person can already sign in. Being filed
     * under a school is what lists an admin among the teachers in the panel.
     */
    private function fileAdminAsTeacher(User $admin, array $schoolIds): JsonResponse
    {
        $before = $admin->schools()->pluck('schools.id');
        $admin->schools()->syncWithoutDetaching($schoolIds);

        if ($change = AuditLogger::idSetChange($before, $admin->schools()->pluck('schools.id'))) {
            $this->audit->log('user.schools_changed', $admin, diff: ['school_ids' => $change]);
        }

        return response()->json([
            'data' => $this->format($admin->load('schools:schools.id,name', 'teachingClassrooms:classrooms.id')),
        ]);
    }

    /**
     * A teacher invited as an admin (BR-98XQ58).
     *
     * Refusing it left the invite form with no way up from teacher, while a
     * student's e-mail has always been promoted in place. So the account is
     * kept — id, name, classes and schools — and only the role changes; the
     * schools keep an admin who teaches on the Učitelé tab (BR-A7KEZ6).
     *
     * A teacher who already signs in gets no invite: opening one would start
     * an expiry on an account that is in use, and the first sign-in after it
     * ran out would be refused. One who never accepted theirs gets a fresh
     * invite, now as an admin, exactly as a resend would.
     */
    private function promoteTeacherToAdmin(User $teacher): JsonResponse
    {
        $reinvite = in_array($teacher->teamStatus(), ['invited', 'expired'], true);

        DB::transaction(function () use ($teacher, $reinvite) {
            $teacher->update(['role' => 'admin']);
            $this->audit->userUpdated($teacher);

            if ($reinvite) {
                $this->openInvite($teacher);
                $this->audit->log('user.invited', $teacher, meta: [
                    'role' => $teacher->role,
                    'existing_account' => true,
                    'school_ids' => [],
                    'invite_expires_at' => $teacher->invite_expires_at->toIso8601String(),
                ]);
            }
        });

        if ($reinvite) {
            $this->sendInvite($teacher);
        }

        return response()->json([
            'data' => $this->format($teacher->load('schools:schools.id,name', 'teachingClassrooms:classrooms.id')),
        ]);
    }

    /**
     * Send a pending or expired invite again. Reuses the account — it only
     * restarts the clock and re-sends the same mail.
     *
     * POST /api/admin/team/{id}/resend
     */
    public function resend(int $id): JsonResponse
    {
        $user = User::whereIn('role', ['admin', 'teacher'])->findOrFail($id);

        if (!in_array($user->teamStatus(), ['invited', 'expired'], true)) {
            return response()->json([
                'message' => 'Znovu poslat lze jen nepřijatou pozvánku nebo pozvánku, které vypršela platnost.',
            ], 422);
        }

        DB::transaction(function () use ($user) {
            $this->openInvite($user);
            $this->audit->log('user.invite_resent', $user, meta: [
                'invite_expires_at' => $user->invite_expires_at->toIso8601String(),
            ]);
        });

        $this->sendInvite($user);

        return response()->json([
            'data' => $this->format($user->load('schools:schools.id,name', 'teachingClassrooms:classrooms.id')),
        ]);
    }

    /**
     * Start (or restart) the invite window. Accepting it is the first admin
     * sign-in, which clears invite_expires_at (AdminAuthController::verify).
     */
    private function openInvite(User $user): void
    {
        $user->update([
            'invited_at' => now(),
            'invite_expires_at' => now()->addDays((int) config('admin.invite_ttl_days', 7)),
        ]);
    }

    /**
     * Tell a freshly created teacher or admin that they have access.
     *
     * Until this existed, an account created here was invisible to its owner —
     * nothing was sent, and the admin had no way to hand the login over except
     * out of band (BR-38FYMX).
     *
     * A failure to send must not fail the creation: the account is already
     * valid and the person can sign in without ever seeing this mail, so the
     * error is reported and swallowed the same way AdminAuthController does.
     *
     * The locale is pinned to Czech rather than taken from the request: the
     * admin proxy forwards the inviting admin's browser Accept-Language, which
     * says nothing about the person being invited, and the admin panel the
     * mail points them at is Czech only (BR-23VT3U).
     */
    private function sendInvite(User $user): void
    {
        $baseUrl = rtrim((string) config('admin.app_url'), '/');

        if ($baseUrl === '') {
            return;
        }

        try {
            Mail::to($user->email)->locale(SetLocale::DEFAULT)->send(new TeamInviteMail(
                name: $user->name,
                role: $user->role,
                loginUrl: $baseUrl . '/login',
                expiresAt: $user->invite_expires_at,
            ));
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * Update name, role, schools or taught classes of a team member.
     *
     * `student` is accepted as a target role: it is how someone leaves the team
     * without the account going anywhere, and it is the same write `destroy()`
     * has always made. Until it was offered here too, the only way to retire an
     * admin was the remove button, so the role select looked like it could only
     * ever move people between admin and teacher (BR-YZJWEA).
     *
     * PUT /api/admin/team/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::whereIn('role', ['admin', 'teacher'])->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'role' => 'sometimes|in:admin,teacher,student',
            // Full replacement of the member's schools (BR-R84FVD).
            'school_ids' => 'sometimes|array',
            'school_ids.*' => 'integer|distinct|exists:schools,id',
            // ...and of the classes they teach (BR-6W6R5Y), the same field
            // and the same rule AdminUserController takes (BR-QKCBCT): only
            // school classes have teachers, never a family's own class.
            'teaching_classroom_ids' => 'sometimes|array',
            'teaching_classroom_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('classrooms', 'id')->where('type', 'school'),
            ],
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        // Prevent self-demotion
        if (isset($validated['role'])
            && $request->user()->id === $user->id
            && $validated['role'] !== $user->role) {
            return response()->json([
                'message' => 'Nemůžete změnit svou vlastní roli.',
            ], 422);
        }

        $schoolIds = $validated['school_ids'] ?? null;
        $teachingClassroomIds = $validated['teaching_classroom_ids'] ?? null;
        unset($validated['school_ids'], $validated['teaching_classroom_ids']);

        $user->update($validated);
        $this->audit->userUpdated($user);

        // After the role write, so a demotion to student in the same save
        // files nobody under a school and leaves nobody teaching a class.
        if ($schoolIds !== null && ($change = $user->syncSchools($schoolIds))) {
            $this->audit->log('user.schools_changed', $user, diff: ['school_ids' => $change]);
        }

        if ($teachingClassroomIds !== null && ($change = $user->syncTeachingClassrooms($teachingClassroomIds))) {
            $this->audit->log('user.teaching_classrooms_changed', $user, diff: ['classroom_ids' => $change]);
        }

        return response()->json([
            'data' => $this->format($user->load('schools:schools.id,name', 'teachingClassrooms:classrooms.id')),
        ]);
    }

    /**
     * Remove a team member (set role back to student).
     * Prevents self-demotion.
     *
     * The member also leaves every school they were filed under. Only team
     * accounts belong in school_teacher, and the school dialog lists that pivot
     * as the school's teachers — it is where "Odebrat" on a teacher lands
     * (BR-9Z59Z9), so a removed teacher left on it would still show up there
     * as one, and would get the old schools back the day they were re-invited.
     *
     * DELETE /api/admin/team/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::whereIn('role', ['admin', 'teacher'])->findOrFail($id);

        // Prevent self-demotion
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'Nemůžete odebrat z týmu svůj vlastní účet.',
            ], 422);
        }

        DB::transaction(function () use ($user) {
            $schoolIds = $user->schools()->pluck('schools.id');

            $user->update(['role' => 'student']);
            $this->audit->userUpdated($user, ['removed_from_team' => true]);

            if ($schoolIds->isNotEmpty()) {
                $user->schools()->detach();
                $this->audit->log('user.schools_changed', $user, diff: [
                    'school_ids' => AuditLogger::idSetChange($schoolIds, []),
                ]);
            }
        });

        return response()->json([
            'message' => 'Člen týmu byl odebrán.',
        ]);
    }

    private function format(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->teamStatus(),
            'invited_at' => $user->invited_at?->toIso8601String(),
            'invite_expires_at' => $user->invite_expires_at?->toIso8601String(),
            // The roster's Deaktivovat / Reaktivovat toggle (BR-Q3ZJ6Q).
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'schools' => $user->schools
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all(),
            // The classes they teach (BR-6W6R5Y) — the same classroom_teacher
            // pivot "Upravit uživatele" has written since BR-QKCBCT, so the
            // team dialog can show and change them too.
            'teaching_classroom_ids' => $user->teachingClassrooms->pluck('id')->all(),
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }
}
