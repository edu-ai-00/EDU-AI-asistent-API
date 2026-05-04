<?php

namespace App\Http\Middleware;

use App\Models\Classroom;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherCourseAccess
{
    /**
     * For teachers, verify they have access to the course in the route.
     *
     * Admins pass through. Teachers must have created the course
     * or have it assigned to one of their classrooms.
     *
     * Usage: middleware('teacher.course:{paramName}')
     * Default paramName is 'id'.
     */
    public function handle(Request $request, Closure $next, string $paramName = 'id'): Response
    {
        if ($request->attributes->get('admin_role') !== 'teacher') {
            return $next($request);
        }

        $courseId = $request->route($paramName);
        $teacherId = $request->user()->id;

        // Check if teacher created the course
        $created = \App\Models\Course::where('id', $courseId)
            ->where('created_by', $teacherId)
            ->exists();

        if ($created) {
            return $next($request);
        }

        // Check if course is assigned to one of teacher's classrooms
        $assigned = Classroom::whereHas('teachers', fn ($q) => $q->where('users.id', $teacherId))
            ->whereHas('courses', fn ($q) => $q->where('courses.id', $courseId))
            ->exists();

        if ($assigned) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Access denied. You do not have access to this course.',
        ], 403);
    }
}
