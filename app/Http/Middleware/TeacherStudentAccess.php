<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherStudentAccess
{
    /**
     * For teachers, verify the student named in the route is one of theirs.
     *
     * Admins pass through. Teachers must have the student in one of the
     * classrooms they teach.
     *
     * This is the per-student twin of TeacherCourseAccess: the course-scoped
     * admin endpoints are gated by course id, but the per-user reports take a
     * student id, and route access alone would let a teacher read any pupil in
     * the school.
     *
     * Usage: middleware('teacher.student:{paramName}')
     * Default paramName is 'userId'.
     */
    public function handle(Request $request, Closure $next, string $paramName = 'userId'): Response
    {
        if ($request->attributes->get('admin_role') !== 'teacher') {
            return $next($request);
        }

        $studentId = (int) $request->route($paramName);

        if ($studentId > 0 && $request->user()->teachesStudent($studentId)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Access denied. This student is not in your classrooms.',
        ], 403);
    }
}
