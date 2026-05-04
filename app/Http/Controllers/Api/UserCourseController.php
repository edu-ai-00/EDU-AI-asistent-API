<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\UserCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserCourseController extends Controller
{
    /**
     * List all courses for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = UserCourse::forUser($request->user()->id)
            ->with('course');

        // Filter by status.
        if ($request->has('status')) {
            $query->withStatus($request->status);
        }

        // Filter by updated_since timestamp.
        if ($request->has('updated_since')) {
            $query->updatedSince($request->updated_since);
        }

        $userCourses = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'user_courses' => $userCourses->map(fn ($uc) => $this->formatUserCourse($uc)),
            'meta' => [
                'total' => $userCourses->count(),
            ],
        ]);
    }

    /**
     * Add a course to the user's library (start/download).
     * Accepts either numeric id or string course_id (e.g. "ZS_MAT_ZLOMKY_5").
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|string',
        ]);

        $userId = $request->user()->id;

        try {
            $course = $this->resolveCourse($validated['course_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Course not found',
                'course_id' => $validated['course_id'],
            ], 404);
        }

        // Check if user already has this course.
        $existing = UserCourse::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Course already in your library',
                'user_course' => $this->formatUserCourse($existing->load('course')),
            ], 200);
        }
        $totalLessons = null;

        if (isset($course->data['lessons']) && is_array($course->data['lessons'])) {
            $totalLessons = count($course->data['lessons']);
        }

        // Create the user course entry.
        $userCourse = UserCourse::create([
            'user_id' => $userId,
            'course_id' => $course->id,
            'status' => 'downloaded',
            'progress_percent' => 0,
            'completed_lessons' => 0,
            'total_lessons' => $totalLessons,
            'current_lesson_index' => 0,
            'time_spent_seconds' => 0,
        ]);

        return response()->json([
            'message' => 'Course added to your library',
            'user_course' => $this->formatUserCourse($userCourse->load('course')),
        ], 201);
    }

    /**
     * Update progress for a user's course.
     * Accepts either numeric id or string course_id (e.g. "ZS_MAT_ZLOMKY_5").
     */
    public function update(Request $request, string $courseId): JsonResponse
    {
        $userId = $request->user()->id;
        $course = $this->resolveCourse($courseId);

        $userCourse = UserCourse::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->firstOrFail();

        $validated = $request->validate([
            'progress_percent' => 'sometimes|integer|min:0|max:100',
            'status' => 'sometimes|in:downloaded,in_progress,completed',
            'completed_lessons' => 'sometimes|integer|min:0',
            'current_lesson_index' => 'sometimes|integer|min:0',
            'time_spent_seconds' => 'sometimes|integer|min:0',
            'progress_data' => 'sometimes|array',
        ]);

        // Handle status transitions.
        if (isset($validated['status'])) {
            $newStatus = $validated['status'];
            $currentStatus = $userCourse->status;

            // Set started_at when transitioning to in_progress.
            if ($newStatus === 'in_progress' && $currentStatus === 'downloaded') {
                $validated['started_at'] = now();
            }

            // Set completed_at when transitioning to completed.
            if ($newStatus === 'completed' && $currentStatus !== 'completed') {
                $validated['completed_at'] = now();
                $validated['progress_percent'] = 100;
            }
        }

        // Auto-update status based on progress.
        if (isset($validated['progress_percent'])) {
            $progress = $validated['progress_percent'];

            if ($progress === 100 && $userCourse->status !== 'completed') {
                $validated['status'] = 'completed';
                $validated['completed_at'] = now();
            } elseif ($progress > 0 && $userCourse->status === 'downloaded') {
                $validated['status'] = 'in_progress';
                $validated['started_at'] = now();
            }
        }

        $userCourse->update($validated);

        return response()->json([
            'message' => 'Progress updated',
            'user_course' => $this->formatUserCourse($userCourse->fresh()->load('course')),
        ]);
    }

    /**
     * Remove a course from the user's library.
     * Accepts either numeric id or string course_id (e.g. "ZS_MAT_ZLOMKY_5").
     */
    public function destroy(Request $request, string $courseId): JsonResponse
    {
        $userId = $request->user()->id;
        $course = $this->resolveCourse($courseId);

        $deleted = UserCourse::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Course not found in your library',
            ], 404);
        }

        return response()->json([
            'message' => 'Course removed from your library',
        ]);
    }

    /**
     * Resolve a course by either numeric id or string course_id.
     * Supports both: "1" (numeric DB id) and "ZS_MAT_ZLOMKY_5" (string course_id).
     */
    private function resolveCourse(string $identifier): Course
    {
        // Try numeric id first, then string course_id.
        if (ctype_digit($identifier)) {
            $course = Course::find((int) $identifier);
            if ($course) {
                return $course;
            }
        }

        return Course::where('course_id', $identifier)->firstOrFail();
    }

    /**
     * Format a user course for API response.
     */
    private function formatUserCourse(UserCourse $userCourse): array
    {
        return [
            'id' => $userCourse->id,
            'user_id' => $userCourse->user_id,
            'course_id' => $userCourse->course?->course_id,  // String identifier (e.g. "ZS_MAT_ZLOMKY_5")
            'server_id' => $userCourse->course_id,            // Numeric FK to courses table
            'progress_percent' => $userCourse->progress_percent,
            'status' => $userCourse->status,
            'completed_lessons' => $userCourse->completed_lessons,
            'total_lessons' => $userCourse->total_lessons,
            'current_lesson_index' => $userCourse->current_lesson_index,
            'time_spent_seconds' => $userCourse->time_spent_seconds,
            'progress_data' => $userCourse->progress_data,
            'started_at' => $userCourse->started_at?->toIso8601String(),
            'completed_at' => $userCourse->completed_at?->toIso8601String(),
            'created_at' => $userCourse->created_at->toIso8601String(),
            'updated_at' => $userCourse->updated_at->toIso8601String(),
            'course' => $userCourse->course ? [
                'id' => $userCourse->course->id,
                'course_id' => $userCourse->course->course_id,
                'name' => $userCourse->course->name,
                'version' => $userCourse->course->version,
                'status' => $userCourse->course->status,
                'language' => $userCourse->course->language,
                'data' => $userCourse->course->data,
            ] : null,
        ];
    }
}
