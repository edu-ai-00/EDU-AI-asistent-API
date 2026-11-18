<?php

namespace App\Notifications;

use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * May this user receive this notification at all? (NOTIF-1 B, spec §2.2 step 2)
 *
 * Returns a reject reason or null. Test sends only need an existing, active
 * account — role and context are what the admin is deliberately overriding.
 */
class RecipientGuard
{
    public const USER_NOT_FOUND = 'user_not_found';
    public const USER_DEACTIVATED = 'user_deactivated';
    public const USER_MERGED = 'user_merged';
    public const ROLE_MISMATCH = 'role_mismatch';
    public const NO_COURSE_ACCESS = 'no_course_access';
    public const NO_CLASSROOM_ACCESS = 'no_classroom_access';
    public const NO_SCHOOL_ACCESS = 'no_school_access';

    /**
     * @param  array{course_id?: int, classroom_id?: int, school_id?: int}|null  $context
     */
    public function rejectReason(
        ?User $user,
        NotificationType $type,
        ?array $context = null,
        bool $isTest = false,
        ?string $targetRole = null,
    ): ?string {
        if ($user === null) {
            return self::USER_NOT_FOUND;
        }

        if ($user->isDeactivated()) {
            return self::USER_DEACTIVATED;
        }

        if ($user->isMerged()) {
            return self::USER_MERGED;
        }

        if ($isTest) {
            return null;
        }

        if (!NotificationType::roleMatches($type->target_role, $user->role)) {
            return self::ROLE_MISMATCH;
        }

        if ($targetRole !== null && !NotificationType::roleMatches($targetRole, $user->role)) {
            return self::ROLE_MISMATCH;
        }

        $context = $context ?? [];

        if (!empty($context['course_id']) && !$this->hasCourse($user, (int) $context['course_id'])) {
            return self::NO_COURSE_ACCESS;
        }

        if (!empty($context['classroom_id']) && !$this->inClassroom($user, (int) $context['classroom_id'])) {
            return self::NO_CLASSROOM_ACCESS;
        }

        if (!empty($context['school_id']) && !$this->inSchool($user, (int) $context['school_id'])) {
            return self::NO_SCHOOL_ACCESS;
        }

        return null;
    }

    private function hasCourse(User $user, int $courseId): bool
    {
        return DB::table('user_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->exists();
    }

    /** A student sitting in the class, or a teacher teaching it. */
    private function inClassroom(User $user, int $classroomId): bool
    {
        $student = DB::table('classroom_student')
            ->where('classroom_id', $classroomId)
            ->where('user_id', $user->id)
            ->exists();

        if ($student) {
            return true;
        }

        return DB::table('classroom_teacher')
            ->where('classroom_id', $classroomId)
            ->where('teacher_id', $user->id)
            ->exists();
    }

    /**
     * Any class of the school (as student or teacher), or a teacher filed
     * under the school itself.
     */
    private function inSchool(User $user, int $schoolId): bool
    {
        $classroomIds = DB::table('classrooms')->where('school_id', $schoolId)->select('id');

        $viaClassroom = DB::table('classroom_student')
            ->where('user_id', $user->id)
            ->whereIn('classroom_id', $classroomIds)
            ->exists()
            || DB::table('classroom_teacher')
                ->where('teacher_id', $user->id)
                ->whereIn('classroom_id', DB::table('classrooms')->where('school_id', $schoolId)->select('id'))
                ->exists();

        if ($viaClassroom) {
            return true;
        }

        return DB::table('school_teacher')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->id)
            ->exists();
    }
}
