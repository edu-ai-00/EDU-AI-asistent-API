<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Consent;
use App\Models\Course;
use App\Models\CourseSkillConfig;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Services\AuditLogger;
use App\Services\ConsentService;
use App\Services\SkillComputationService;
use App\Support\StudentCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminClassroomController extends Controller
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a classroom with pre-generated students.
     *
     * POST /api/admin/classrooms
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeJoinCodeInput($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'school_id' => 'sometimes|nullable|integer|exists:schools,id',
            'students' => 'required|array|min:1',
            'students.*.name' => 'required|string|max:255',
            'students.*.login_code' => 'required|string|size:6|alpha_num|distinct|unique:users,login_code',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
            'join_code' => $this->joinCodeRules(),
        ], $this->joinCodeMessages());

        $this->assertNoLoginCodeHashCollisions($validated['students']);

        $user = $request->user();

        // A teacher creating a class always gets exactly themselves. Only an
        // admin picks the roster — same rule as update().
        if ($request->attributes->get('admin_role') === 'teacher') {
            $validated['teacher_ids'] = [$user->id];
        }

        // Which school a class belongs to decides who else can see it exists,
        // so only an admin files it — same rule as update() (BR-BERMY7).
        $schoolId = $request->attributes->get('admin_role') === 'admin'
            ? ($validated['school_id'] ?? null)
            : null;

        $classroom = DB::transaction(function () use ($validated, $user, $schoolId) {
            $classroom = Classroom::create([
                'name' => $validated['name'],
                'school_id' => $schoolId,
                'join_code' => $validated['join_code'] ?? null,
            ]);

            // Attach teachers — default to current user if authenticated, otherwise none
            $teacherIds = $validated['teacher_ids'] ?? ($user ? [$user->id] : []);
            if (!empty($teacherIds)) {
                $classroom->teachers()->attach($teacherIds);
            }

            $studentIds = [];

            foreach ($validated['students'] as $studentData) {
                $loginCode = strtoupper($studentData['login_code']);

                // Both columns, always: CodeController::resolve() looks up
                // only by login_code_hash (BR-8FDF37); the plaintext stays
                // solely so a teacher can re-read it in admin. Written
                // explicitly here rather than left to User's saving-hook: an
                // explicit write to login_code_hash makes that hook stand
                // down entirely (see User::booted()), so this is the one
                // mechanism actually in effect — not a redundant mirror of
                // it — and the invariant stays visible at the one place
                // school codes are minted.
                $student = User::create([
                    'name' => $studentData['name'],
                    'login_code' => $loginCode,
                    'login_code_hash' => StudentCodes::hash($loginCode),
                ]);
                $student->classrooms()->attach($classroom->id);
                $studentIds[] = $student->id;
            }

            $this->audit->log('classroom.created', $classroom, diff: AuditLogger::created($classroom, ['name', 'school_id']) + array_filter([
                'teacher_ids' => AuditLogger::idSetChange([], $teacherIds),
            ]), meta: [
                'student_ids' => $studentIds,
            ]);

            if ($classroom->join_code !== null) {
                $this->audit->log('classroom.join_code_changed', $classroom, diff: [
                    'join_code' => AuditLogger::pair('join_code', null, $classroom->join_code),
                ]);
            }

            return $classroom;
        });

        $classroom->load($this->detailRelations());

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ], 201);
    }

    /**
     * List classrooms with student counts.
     * Teacher: only own classrooms. Admin: all.
     *
     * GET /api/admin/classrooms
     * GET /api/admin/classrooms?type=self_service
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|string|in:school,self_service',
        ]);

        // School classes are the default view. Guardian-owned self-service
        // classes (BR-8FDF37) only show up when asked for, so they cannot
        // flood a teacher's or admin's ordinary classroom list.
        $type = $request->input('type', 'school');

        $query = Classroom::withCount('students')
            ->with('teachers:id,name', 'guardian:id,email', 'school:id,name')
            ->where('type', $type);

        // Teacher scoping: classrooms they teach, plus — since BR-8QAWKP —
        // every other class filed under a school they belong to. Note this
        // already excludes every self-service class on its own merits (
        // provisioning never attaches a teacher to one, nor files one under a
        // school), but the type filter above is the actual, explicit guarantee.
        //
        // The school-mates arrive read-only: `is_own` below is false for them
        // and show()/update() still refuse. Nothing beyond the class name and
        // its teachers is ever loaded for them, which is exactly what the
        // client asked to be visible.
        $ownIds = null;

        if ($request->attributes->get('admin_role') === 'teacher') {
            $teacher = $request->user();
            $ownIds = $teacher->teachingClassrooms()->pluck('classrooms.id')->all();
            $schoolIds = $teacher->schools()->pluck('schools.id')->all();

            $query->where(function ($q) use ($ownIds, $schoolIds) {
                $q->whereIn('id', $ownIds);

                if (!empty($schoolIds)) {
                    $q->orWhereIn('school_id', $schoolIds);
                }
            });
        }

        $classrooms = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $classrooms->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'guardian_email' => $c->guardian?->email,
                'students_count' => $c->students_count,
                'school' => $c->school ? ['id' => $c->school->id, 'name' => $c->school->name] : null,
                // Only for the teacher's own classes: a school-mate may see the
                // class exists, but its code would let them walk into it.
                'join_code' => $ownIds === null || in_array($c->id, $ownIds, true) ? $c->join_code : null,
                // Admins own everything; for a teacher this is what separates
                // their own classes from the school-mates they may only see.
                'is_own' => $ownIds === null || in_array($c->id, $ownIds, true),
                'teachers' => $c->teachers->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                ]),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get classroom detail with all students, teachers, and courses.
     * Teacher: ownership check. Admin: any.
     *
     * GET /api/admin/classrooms/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::with($this->detailRelations())->findOrFail($id);

        // Teacher can only view classrooms they teach. Since BR-8QAWKP a
        // teacher can also *see* a school-mate's class in the list, so this
        // 403 is now a route the UI reaches by design — it names the reason so
        // the admin can show "this is not your class" rather than a bare error.
        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json([
                'message' => 'Access denied. This class is not yours.',
                'reason' => 'not_own_classroom',
            ], 403);
        }

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ]);
    }

    /**
     * Update a classroom (name, class code, school, teachers).
     *
     * PUT /api/admin/classrooms/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        // Teacher can only update classrooms they teach
        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $this->normalizeJoinCodeInput($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'school_id' => 'sometimes|nullable|integer|exists:schools,id',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
            'join_code' => $this->joinCodeRules($classroom),
        ], $this->joinCodeMessages());

        // A guardian's family class (BR-8FDF37) is never something a stranger
        // walks into. Refused before anything is written, so a rejected save
        // does not half-apply.
        if (($validated['join_code'] ?? null) !== null && $classroom->isSelfService()) {
            throw ValidationException::withMessages([
                'join_code' => 'Samoobslužná rodinná třída nemůže mít kód třídy.',
            ]);
        }

        $before = $classroom->only(['name', 'school_id', 'join_code']);

        if (isset($validated['name'])) {
            $classroom->update(['name' => $validated['name']]);
        }

        // The class code (BR-TT6TD7) is the teacher's to set, like the name:
        // it only opens their own class, to pupils they would add anyway.
        // Sending null (or an empty field) switches self-service joining off.
        if (array_key_exists('join_code', $validated)) {
            $classroom->update(['join_code' => $validated['join_code']]);
        }

        // Which school a class belongs to decides who else can see it exists,
        // so it is filed by an admin only — same reasoning as the roster below.
        if (array_key_exists('school_id', $validated)
            && $request->attributes->get('admin_role') === 'admin') {
            $classroom->update(['school_id' => $validated['school_id']]);
        }

        // Who teaches a classroom is an admin decision. A teacher editing
        // their own classroom may rename it, but syncing the roster would let
        // them hand the class to someone else — or drop themselves and lose
        // access to their own students.
        if (isset($validated['teacher_ids'])
            && $request->attributes->get('admin_role') === 'admin') {
            $teachersBefore = $classroom->teachers()->pluck('users.id');
            $classroom->teachers()->sync($validated['teacher_ids']);

            if ($change = AuditLogger::idSetChange($teachersBefore, $validated['teacher_ids'])) {
                $this->audit->log('classroom.teachers_changed', $classroom, diff: ['teacher_ids' => $change]);
            }
        }

        $this->logClassroomUpdate($classroom, $before);

        $classroom->load($this->detailRelations());

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ]);
    }

    /**
     * Add students to an existing classroom.
     * Also auto-enrolls them in any courses already assigned to the classroom.
     *
     * POST /api/admin/classrooms/{id}/students
     */
    public function addStudents(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'students' => 'required|array|min:1|max:50',
            'students.*.name' => 'required|string|max:255',
            'students.*.login_code' => 'required|string|size:6|alpha_num|distinct|unique:users,login_code',
        ]);

        $this->assertNoLoginCodeHashCollisions($validated['students']);

        DB::transaction(function () use ($classroom, $validated) {
            $newStudentIds = [];

            foreach ($validated['students'] as $studentData) {
                $loginCode = strtoupper($studentData['login_code']);

                $student = User::create([
                    'name' => $studentData['name'],
                    'login_code' => $loginCode,
                    'login_code_hash' => StudentCodes::hash($loginCode),
                ]);
                $student->classrooms()->attach($classroom->id);
                $newStudentIds[] = $student->id;
            }

            $this->audit->log('classroom.students_added', $classroom, meta: [
                'student_ids' => $newStudentIds,
            ]);

            // Auto-enroll new students in classroom's assigned courses
            $courseIds = $classroom->courses()->pluck('courses.id');
            foreach ($courseIds as $courseId) {
                foreach ($newStudentIds as $studentId) {
                    UserCourse::firstOrCreate([
                        'user_id' => $studentId,
                        'course_id' => $courseId,
                    ], [
                        'status' => 'downloaded',
                        'progress_percent' => 0,
                        'downloaded_version' => 0,
                        'downloaded_at' => now(),
                    ]);
                }
            }
        });

        $classroom->load($this->detailRelations());

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ], 201);
    }

    /**
     * Delete a classroom and its student accounts.
     *
     * DELETE /api/admin/classrooms/{id} (admin-only, BR-3JZWEY)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($classroom->isSelfService()) {
            $message = $this->offboardSelfServiceClassroom($classroom, $request);

            return response()->json(['message' => $message]);
        }

        DB::transaction(function () use ($classroom) {
            $erasedIds = [];

            // Erase only the placeholder accounts the teacher minted for
            // this class: no email, not a guest, and in no other class. A
            // real account that joined by student code (BR-3V2P22) — or a
            // placeholder that is also on another roster — merely leaves;
            // the pivot's cascadeOnDelete does the detaching.
            $classroom->students()
                ->whereNull('users.email')
                ->where(fn ($q) => $q->whereNull('users.is_guest')->orWhere('users.is_guest', false))
                ->whereNotExists(function ($sub) use ($classroom) {
                    $sub->selectRaw('1')
                        ->from('classroom_student as other')
                        ->whereColumn('other.user_id', 'users.id')
                        ->where('other.classroom_id', '!=', $classroom->id);
                })
                ->get(['users.id'])
                ->each(function (User $student) use (&$erasedIds) {
                    $erasedIds[] = $student->id;
                    $student->delete();
                });

            $classroom->delete();

            $this->audit->log('classroom.deleted', $classroom, meta: [
                'name' => $classroom->name,
                'erased_student_ids' => $erasedIds,
            ]);
        });

        return response()->json([
            'message' => 'Classroom deleted successfully',
        ]);
    }

    /**
     * A self-service classroom is not really its own thing to delete: its
     * children belong to the guardian (users.guardian_id), not to the
     * classroom (classroom_student rows cascade away with it). A bare
     * `$classroom->delete()` would therefore silently DETACH the children
     * instead of removing them — they would keep their confirmed consent and
     * working login codes, just with no classroom, invisible to this very
     * listing from then on and unreachable by consent:purge-revoked (which
     * only ever looks at status=revoked, never at "confirmed but classroom
     * mysteriously gone").
     *
     * An earlier version of this method went to the opposite extreme instead:
     * erase the children and the guardian immediately, right here, behind a
     * plain delete button. That is strictly worse than the detach bug it was
     * fixing — one admin misclick becomes instant, silent, unrecoverable
     * data loss for a family, with no notification to the guardian at all.
     *
     * So this is not a delete. It is a revoke, via the exact path a guardian
     * reaches from their own magic-link management page
     * (ConsentManageController::revoke() → ConsentService::revoke()):
     * children's Sanctum tokens are killed and CodeController rejects them
     * immediately, but the account rows themselves are only *scheduled* for
     * erasure (`purge_after`, config('consent.purge_after_days') — 30 days
     * today) for consent:purge-revoked to carry out later. The guardian is
     * emailed the same ConsentRevokedMail a self-service revocation always
     * sends, so a family is never removed without being told. A mistaken
     * click is recoverable for the whole grace period — nothing here is
     * irreversible the instant it is clicked.
     *
     * The classroom row itself is deliberately left alone here: it still
     * legitimately represents the guardian's (now revoked) self-service
     * class until consent:purge-revoked deletes it along with everything
     * else once the grace period lapses. It stays visible in this listing
     * in the meantime — that is correct, not a bug, since the data has not
     * actually been erased yet.
     *
     * @return string a human-readable outcome for the response body
     */
    private function offboardSelfServiceClassroom(Classroom $classroom, Request $request): string
    {
        $guardianId = $classroom->guardian_id;

        if ($guardianId === null) {
            // Defensive only: provisioning always sets guardian_id on a
            // self_service row, but the column is nullable at the schema
            // level. There is no guardian to revoke anything for — nothing
            // but the classroom row itself was ever created.
            $classroom->delete();
            $this->audit->log('classroom.deleted', $classroom, meta: ['name' => $classroom->name, 'self_service' => true]);

            return 'Classroom deleted successfully';
        }

        // The consent that currently governs this guardian's children, if
        // any. governingFor() (not a plain status=confirmed lookup) is the
        // same fail-closed resolver CodeController uses, so this agrees with
        // the login path on what "currently active" means.
        $governing = Consent::governingFor($guardianId);

        if ($governing && $governing->isConfirmed()) {
            // The normal case: a live self-service class with children who
            // can currently log in. Revoke it — same effect, same audit
            // trail, same email as the guardian doing this themselves.
            $this->consents->revoke($governing, $request);

            return sprintf(
                'Souhlas byl odvolán. Účty budou nevratně smazány za %d dní, pokud rodič znovu neschválí souhlas.',
                (int) config('consent.purge_after_days'),
            );
        }

        // Nothing is currently governing this guardian: either their consent
        // was already revoked (by themselves, or by a previous admin action
        // through this same endpoint) and is simply waiting out its own
        // purge_after, or this is leftover data from a flow that never
        // reached a governing state at all — not reachable through
        // ConsentService today (a self-service classroom is only ever
        // created by confirm(), which always leaves a CONFIRMED row behind),
        // but not worth trusting that invariant blindly here either.
        //
        // Either way there is nothing left to lock out — a non-governing
        // consent already cannot authorise a login — so there is nothing to
        // revoke. What IS true is that leaving the classroom permanently
        // stuck in this listing with no way to clear it is its own bug: the
        // real erasure is scheduled and will happen on its own via
        // consent:purge-revoked (which finds the children by guardian_id,
        // not by classroom membership, so removing this row does not affect
        // it), so it is safe to just remove the now-redundant classroom row
        // and let the scheduled purge finish the rest on its original
        // timeline — no shortcut around the grace period, no second email.
        $classroom->delete();
        $this->audit->log('classroom.deleted', $classroom, meta: ['name' => $classroom->name, 'self_service' => true]);

        return 'Classroom removed. Any pending data erasure for this guardian continues on its original schedule.';
    }

    /**
     * Get courses assigned to a classroom.
     *
     * GET /api/admin/classrooms/{id}/courses
     */
    public function getCourses(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $courses = $classroom->courses()
            ->select('courses.id', 'courses.course_id', 'courses.name', 'courses.emoji', 'courses.status')
            ->get();

        return response()->json([
            'data' => $courses->map(fn ($c) => [
                'id' => $c->id,
                'course_id' => $c->course_id,
                'name' => $c->name,
                'emoji' => $c->emoji,
                'status' => $c->status,
                'assigned_at' => $c->pivot->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Assign courses to a classroom. Bulk-enrolls all current students.
     *
     * POST /api/admin/classrooms/{id}/courses
     */
    public function assignCourses(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        DB::transaction(function () use ($classroom, $validated) {
            // Attach courses to classroom (ignore duplicates)
            $attached = $classroom->courses()->syncWithoutDetaching($validated['course_ids'])['attached'];

            if (!empty($attached)) {
                $this->audit->log('classroom.courses_assigned', $classroom, meta: [
                    'course_ids' => array_values($attached),
                ]);
            }

            // Bulk-enroll all current students
            $studentIds = $classroom->students()->pluck('users.id');
            foreach ($validated['course_ids'] as $courseId) {
                foreach ($studentIds as $studentId) {
                    UserCourse::firstOrCreate([
                        'user_id' => $studentId,
                        'course_id' => $courseId,
                    ], [
                        'status' => 'downloaded',
                        'progress_percent' => 0,
                        'downloaded_version' => 0,
                        'downloaded_at' => now(),
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Courses assigned successfully',
        ]);
    }

    /**
     * Remove a course from a classroom (does NOT delete student enrollments).
     *
     * DELETE /api/admin/classrooms/{id}/courses/{courseId}
     */
    public function removeCourse(Request $request, int $id, int $courseId): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($classroom->courses()->detach($courseId) > 0) {
            $this->audit->log('classroom.course_removed', $classroom, meta: ['course_id' => $courseId]);
        }

        return response()->json([
            'message' => 'Course removed from classroom',
        ]);
    }

    /**
     * Skills overview for a classroom filtered by course.
     *
     * GET /api/admin/classrooms/{id}/skills/overview?course_id=10
     */
    public function skillsOverview(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
        ]);

        $courseId = $validated['course_id'];
        $aggregated = $request->boolean('aggregated', false);

        // Get config (or default). min_count is deliberately not applied: the
        // first answer in a dimension seeds its ELO without bumping
        // profil_pocet, so a task-count gate hid students whose detail page
        // and app already show their skills (BR-87QQEU). Like those, a
        // dimension counts once it has an ELO; aggregated mode takes any, and
        // "pouze kurz" those this course practised at least once.
        $config = CourseSkillConfig::where('course_id', $courseId)->first();
        $formulaJson = $config?->formula_json ?? SkillComputationService::defaultGpfFormula();
        $confidenceC = $config?->confidence_c ?? 2.5;
        $minCount = $aggregated ? 0 : 1;
        $scaleMin = $config?->display_scale_min ?? 1.0;
        $scaleMax = $config?->display_scale_max ?? 10.0;

        // Get student IDs in this classroom
        $classroomStudentIds = $classroom->students()->pluck('users.id');

        // Get enrollments for this course filtered to classroom students
        $enrollments = UserCourse::where('course_id', $courseId)
            ->whereIn('user_id', $classroomStudentIds)
            ->with('user:id,name,email', 'user.classrooms:classrooms.id,name')
            ->get();

        $studentIds = $enrollments->pluck('user_id')->all();

        // Load ELO profiles
        $profiles = UserEloProfile::whereIn('user_id', $studentIds)
            ->get()
            ->keyBy('user_id');

        // For "pouze kurz" mode: build per-user task counts from this course's interactions only
        $courseOnlyPocet = [];
        if (!$aggregated) {
            // Interactions store the string course_id, not the numeric id
            $interactions = EloInteraction::whereIn('user_id', $studentIds)
                ->where('course_id', Course::whereKey($courseId)->value('course_id'))
                ->get();

            foreach ($interactions as $interaction) {
                $uid = $interaction->user_id;
                if (!isset($courseOnlyPocet[$uid])) {
                    $courseOnlyPocet[$uid] = array_fill(0, 35, 0);
                }
                $indices = $interaction->updated_indices ?? [];
                foreach ($indices as $dimIdx) {
                    if ($dimIdx >= 0 && $dimIdx < 35) {
                        $courseOnlyPocet[$uid][$dimIdx]++;
                    }
                }
            }
        }

        $skillNames = array_keys($formulaJson);

        // Compute per-student
        $students = [];
        foreach ($enrollments as $enrollment) {
            $profile = $profiles->get($enrollment->user_id);
            $profilElo = $profile?->profil_elo;
            // Use course-only counts when not aggregated
            $profilPocet = $aggregated
                ? ($profile?->profil_pocet)
                : ($courseOnlyPocet[$enrollment->user_id] ?? null);

            if (!$profilElo) {
                $students[] = [
                    'user' => $enrollment->user ? [
                        'id' => $enrollment->user->id,
                        'name' => $enrollment->user->name,
                        'classroom_ids' => $enrollment->user->classrooms->pluck('id')->all(),
                        'classroom_name' => $enrollment->user->classroomNames() ?: null,
                    ] : null,
                    'skills' => array_map(fn (string $name) => [
                        'name' => $name,
                        'level' => null,
                        'interval_low' => null,
                        'interval_high' => null,
                        'confidence_label' => null,
                        'included_count' => 0,
                        'excluded_count' => 0,
                        'median_count' => null,
                    ], $skillNames),
                    'has_data' => false,
                ];
                continue;
            }

            $skills = SkillComputationService::computeSkills(
                profilElo: $profilElo,
                profilPocet: $profilPocet ?? array_fill(0, count($profilElo), 0),
                formulaJson: $formulaJson,
                confidenceC: $confidenceC,
                minCount: $minCount,
                scaleMin: $scaleMin,
                scaleMax: $scaleMax,
            );

            $students[] = [
                'user' => $enrollment->user ? [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'classroom_ids' => $enrollment->user->classrooms->pluck('id')->all(),
                    'classroom_name' => $enrollment->user->classroomNames() ?: null,
                ] : null,
                'skills' => $skills,
                'has_data' => true,
            ];
        }

        return response()->json([
            'data' => [
                'course_id' => $courseId,
                'skill_names' => $skillNames,
                'students' => $students,
            ],
            'meta' => [
                'total_students' => count($students),
                'students_with_data' => collect($students)->where('has_data', true)->count(),
                'aggregated' => $aggregated,
            ],
        ]);
    }

    /**
     * Get all courses that students in this classroom are enrolled in.
     *
     * GET /api/admin/classrooms/{id}/enrolled-courses
     */
    public function enrolledCourses(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $studentIds = $classroom->students()->pluck('users.id');

        $courseIds = UserCourse::whereIn('user_id', $studentIds)
            ->distinct()
            ->pluck('course_id');

        $courses = Course::whereIn('id', $courseIds)
            ->withCount(['userCourses as enrolled_count' => fn ($q) => $q->whereIn('user_id', $studentIds)])
            ->get();

        return response()->json([
            'data' => $courses->map(fn ($c) => [
                'id' => $c->id,
                'course_id' => $c->course_id,
                'name' => $c->name,
                'emoji' => $c->emoji,
                'status' => $c->status,
                'enrolled_count' => $c->enrolled_count,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * update() writes each field with its own save, so the diff is taken
     * against a snapshot rather than getChanges(). The class code gets its
     * own event (BR-BNNASY asks for code creation/change/use to be findable
     * on their own), always redacted — it is the key to the class.
     *
     * @param  array{name: string, school_id: ?int, join_code: ?string}  $before
     */
    private function logClassroomUpdate(Classroom $classroom, array $before): void
    {
        $diff = [];

        foreach (['name', 'school_id'] as $field) {
            // Compared as strings: an id can come back from the request as
            // "3" and from the database as 3 without anything having changed.
            if ((string) $before[$field] !== (string) $classroom->{$field}) {
                $diff[$field] = AuditLogger::pair($field, $before[$field], $classroom->{$field});
            }
        }

        if ($diff) {
            $this->audit->log('classroom.updated', $classroom, diff: $diff);
        }

        if ($before['join_code'] !== $classroom->join_code) {
            $this->audit->log('classroom.join_code_changed', $classroom, diff: [
                'join_code' => AuditLogger::pair('join_code', $before['join_code'], $classroom->join_code),
            ]);
        }
    }

    /**
     * Validation catches an exact plaintext duplicate ('distinct' /
     * 'unique:users,login_code') but not a HASH collision: two different
     * plaintext codes that normalise the same way (I/L→1, O→0 — e.g.
     * ABC012 and ABCO12) share a login_code_hash even though neither
     * 'distinct' nor 'unique:users,login_code' ever compares them. Without
     * this, that case reaches the database's unique index on
     * login_code_hash and throws a raw UniqueConstraintViolationException —
     * a 500 with no indication to the teacher which of up to 50 codes was
     * the problem (BR-8FDF37 I6). Checked against both codes already
     * submitted in this same request and codes already in the database
     * (which includes minors' hashes, not just other school codes).
     */
    private function assertNoLoginCodeHashCollisions(array $students): void
    {
        $seenInBatch = [];

        foreach ($students as $index => $studentData) {
            $code = strtoupper($studentData['login_code']);
            $hash = StudentCodes::hash($code);

            if (isset($seenInBatch[$hash])) {
                throw ValidationException::withMessages([
                    "students.{$index}.login_code" => "Kód {$code} po normalizaci (I/L→1, O→0) koliduje s kódem {$seenInBatch[$hash]} v tomto požadavku.",
                ]);
            }

            if (User::where('login_code_hash', $hash)->exists()) {
                throw ValidationException::withMessages([
                    "students.{$index}.login_code" => "Kód {$code} po normalizaci (I/L→1, O→0) koliduje s kódem existujícího účtu.",
                ]);
            }

            $seenInBatch[$hash] = $code;
        }
    }

    /**
     * Class codes are stored upper case, so fold what was typed before the
     * unique rule compares it — "ab12" must collide with an existing "AB12".
     */
    private function normalizeJoinCodeInput(Request $request): void
    {
        if (is_string($request->input('join_code'))) {
            $code = Classroom::normalizeJoinCode($request->input('join_code'));
            $request->merge(['join_code' => $code === '' ? null : $code]);
        }
    }

    /** @return array<int, mixed> */
    private function joinCodeRules(?Classroom $classroom = null): array
    {
        $unique = Rule::unique('classrooms', 'join_code');

        return [
            'sometimes',
            'nullable',
            'string',
            'regex:' . Classroom::JOIN_CODE_REGEX,
            $classroom ? $unique->ignore($classroom->id) : $unique,
        ];
    }

    /** @return array<string, string> */
    private function joinCodeMessages(): array
    {
        return [
            'join_code.regex' => 'Kód třídy musí mít přesně 4 znaky – písmena A–Z nebo číslice.',
            'join_code.unique' => 'Tento kód třídy už používá jiná třída. Zvolte jiný.',
        ];
    }

    /**
     * What formatClassroomDetail() reads. The roster is ordered by when each
     * student joined the class — the order of the printed code list. Left
     * unordered, Postgres returns the joined rows in heap order, and a
     * student who renamed themselves in the app (an UPDATE writes a new row
     * version) dropped from their slot to the bottom of the list under a
     * name the teacher had never seen, so their progress looked missing
     * (BR-7UPBD7).
     *
     * @return array<int|string, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'students' => fn ($query) => $query
                ->select('users.id', 'users.name', 'users.email', 'users.login_code')
                ->orderBy('classroom_student.id'),
            'teachers:id,name,email',
            'courses:courses.id,course_id,name,emoji',
        ];
    }

    private function formatClassroomDetail(Classroom $classroom): array
    {
        // Eager-load stats and course enrollments for students
        $studentIds = $classroom->students->pluck('id');

        $stats = \App\Models\UserStats::whereIn('user_id', $studentIds)
            ->get()
            ->keyBy('user_id');

        $enrollments = UserCourse::whereIn('user_id', $studentIds)
            ->get()
            ->groupBy('user_id');

        return [
            'id' => $classroom->id,
            'name' => $classroom->name,
            'join_code' => $classroom->join_code,
            'students_count' => $classroom->students->count(),
            'teachers' => $classroom->teachers->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
            ]),
            'courses' => $classroom->courses->map(fn ($c) => [
                'id' => $c->id,
                'course_id' => $c->course_id,
                'name' => $c->name,
                'emoji' => $c->emoji,
                'assigned_at' => $c->pivot->created_at?->toIso8601String(),
            ]),
            'students' => $classroom->students->map(function ($s) use ($stats, $enrollments) {
                $userStats = $stats->get($s->id);
                $userEnrollments = $enrollments->get($s->id, collect());
                $completedCount = $userEnrollments->where('status', 'completed')->count();

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'email' => $s->email,
                    'login_code' => $s->login_code,
                    'stats' => $userStats ? [
                        'level' => $userStats->level,
                        'xp_points' => $userStats->xp_points,
                        'streak_days' => $userStats->streak_days,
                    ] : null,
                    'courses_count' => $userEnrollments->count(),
                    'completed_count' => $completedCount,
                    'last_active_at' => $userStats?->updated_at?->toIso8601String(),
                ];
            }),
            'created_at' => $classroom->created_at->toIso8601String(),
        ];
    }
}
