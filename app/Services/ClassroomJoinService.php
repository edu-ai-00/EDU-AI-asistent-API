<?php

namespace App\Services;

use App\Exceptions\ClassroomJoinException;
use App\Models\Classroom;
use App\Models\User;
use App\Models\UserCourse;
use App\Support\StudentCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ClassroomJoinService — lets an existing account join a class by typing
 * the student code a teacher handed out (BR-3V2P22).
 *
 * A student code is not a class code: it is the login code of the
 * placeholder account AdminClassroomController minted on the roster. So
 * "joining" means folding that placeholder into the account the student
 * already uses — UserMergeService does the folding (progress, burned code)
 * and its mergeClassrooms strategy moves the membership. Nothing here
 * writes to the DB directly.
 *
 * resolve() is the dry run behind the app's "Chceš se připojit ke třídě X?"
 * dialog; join() is the same checks followed by the merge.
 *
 * Since BR-TT6TD7 a class can also carry a class code of its own
 * (classrooms.join_code, four characters, set by the teacher). That one
 * really is a class code: resolveClassCode()/joinByClassCode() put the
 * caller straight on the roster — no placeholder, no merge — and enroll
 * them in the courses the class already has, the same as a pupil the
 * teacher adds by hand. Who may join is the same for both kinds of code —
 * since BR-BERW9B that includes teachers and admins.
 */
class ClassroomJoinService
{
    public function __construct(
        private readonly UserMergeService $merge,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Find the placeholder behind a code and the class it sits in, or
     * explain why the caller cannot use it.
     *
     * @return array{0: User, 1: Classroom} placeholder, classroom
     *
     * @throws ClassroomJoinException
     */
    public function resolve(User $caller, string $code): array
    {
        $this->assertCanJoin($caller);

        // Same lookup as CodeController::resolve() — hash only, never the
        // plaintext column (BR-8FDF37).
        $placeholder = User::where('login_code_hash', StudentCodes::hash($code))->first();

        if (!$placeholder) {
            throw new ClassroomJoinException('Kód třídy nebyl nalezen.', 404);
        }
        if ($placeholder->id === $caller->id) {
            throw new ClassroomJoinException('To je kód tvého vlastního účtu.', 409);
        }
        if ($placeholder->isMerged()) {
            // Codes are burned at merge and never reissued (spec decision #2),
            // so a merged hit means this exact code was already used.
            throw new ClassroomJoinException('Tento kód už byl použit.', 410);
        }
        if ($placeholder->is_minor) {
            throw new ClassroomJoinException('Tento kód patří k dětskému účtu a nelze ho spojit s jiným účtem.', 403);
        }
        // A placeholder is a bare code account: no email, not a guest, not
        // staff. Anything else with that code is somebody's real account.
        if (!empty($placeholder->email) || $placeholder->is_guest || $placeholder->isAdminOrTeacher()) {
            throw new ClassroomJoinException('Tento kód už patří jinému účtu.', 409);
        }

        // The roster the teacher minted the code on. A placeholder normally
        // sits in exactly one class; the oldest membership is "the" class.
        $classroom = $placeholder->classrooms()->orderBy('classroom_student.id')->first();

        if (!$classroom) {
            throw new ClassroomJoinException('Kód není přiřazen k žádné třídě.', 422);
        }
        $this->assertNotMember($caller, $classroom);

        return [$placeholder, $classroom];
    }

    /**
     * Perform the join: merge the placeholder into the caller's account.
     *
     * @throws ClassroomJoinException
     */
    public function join(User $caller, string $code): Classroom
    {
        [$placeholder, $classroom] = $this->resolve($caller, $code);

        try {
            // performed_by = caller: self-service, the surviving account is
            // the actor, same convention as absorbGuest(). Direction is
            // placeholder → caller; enforceDirection() agrees because the
            // placeholder never has an email. The caller may be a teacher
            // or an admin (BR-BERW9B); resolve() already made sure the
            // source is a bare placeholder, never a staff account.
            $merge = $this->merge->merge($placeholder->id, $caller->id, $caller->id, allowStaffTarget: true);
        } catch (ValidationException $e) {
            // The merge re-checks its own guards under row locks; losing a
            // race (two devices, one code) lands here rather than in
            // resolve()'s pre-checks.
            $first = collect($e->errors())->flatten()->first();

            throw new ClassroomJoinException($first ?: 'Ke třídě se teď nelze připojit.', 422);
        }

        $this->audit->log('classroom.joined', $classroom, meta: [
            'via' => 'student_code',
            'placeholder_user_id' => $placeholder->id,
            'merge_id' => $merge->id,
        ]);

        return $classroom;
    }

    /**
     * Find the class behind a class code (BR-TT6TD7), or explain why the
     * caller cannot use it.
     *
     * @throws ClassroomJoinException
     */
    public function resolveClassCode(User $caller, string $code): Classroom
    {
        $this->assertCanJoin($caller);

        // Only school classes carry a code; a guardian's self-service class
        // (BR-8FDF37) is a family, never something a stranger walks into.
        $classroom = Classroom::where('type', 'school')
            ->where('join_code', Classroom::normalizeJoinCode($code))
            ->first();

        if (!$classroom) {
            throw new ClassroomJoinException('Kód třídy nebyl nalezen.', 404);
        }

        $this->assertNotMember($caller, $classroom);

        return $classroom;
    }

    /**
     * Join by class code: put the caller on the roster and enroll them in
     * the class's courses, the same as AdminClassroomController::addStudents
     * does for a pupil the teacher adds.
     *
     * @throws ClassroomJoinException
     */
    public function joinByClassCode(User $caller, string $code): Classroom
    {
        $classroom = $this->resolveClassCode($caller, $code);

        DB::transaction(function () use ($caller, $classroom) {
            // syncWithoutDetaching + the pivot's unique(classroom_id, user_id):
            // a double tap or a second device cannot add the row twice.
            $caller->classrooms()->syncWithoutDetaching([$classroom->id]);

            foreach ($classroom->courses()->pluck('courses.id') as $courseId) {
                UserCourse::firstOrCreate([
                    'user_id' => $caller->id,
                    'course_id' => $courseId,
                ], [
                    'status' => 'downloaded',
                    'progress_percent' => 0,
                    'downloaded_version' => 0,
                    'downloaded_at' => now(),
                ]);
            }

            $this->audit->log('classroom.joined', $classroom, meta: ['via' => 'class_code']);
        });

        return $classroom;
    }

    /**
     * A guest has nothing to fold a placeholder INTO — they should log in
     * with the code instead (CodeController), which absorbs the guest — and
     * for a class code they have no account a teacher could see on the
     * roster either. Minors are consent-bound pseudonymous accounts and
     * merges are refused for them outright (UserMergeService guard 5).
     *
     * Teachers and admins may join (BR-BERW9B): signed in to the app they
     * are learners like anyone else, and keep their role. What a class
     * teacher may then do to such an account is AdminUserController's
     * business (read yes, edit no).
     *
     * @throws ClassroomJoinException
     */
    private function assertCanJoin(User $caller): void
    {
        if ($caller->is_guest) {
            throw new ClassroomJoinException('Nejdřív se přihlas nebo zaregistruj.', 403);
        }
        if ($caller->is_minor) {
            throw new ClassroomJoinException('Tento účet se ke třídě připojit nemůže.', 403);
        }
    }

    /** @throws ClassroomJoinException */
    private function assertNotMember(User $caller, Classroom $classroom): void
    {
        if ($caller->classrooms()->whereKey($classroom->id)->exists()) {
            throw new ClassroomJoinException("Už jsi ve třídě {$classroom->name}.", 409);
        }
    }
}
