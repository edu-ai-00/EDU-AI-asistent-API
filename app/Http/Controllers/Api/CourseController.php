<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadCourseRequest;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\UserCourse;
use App\Services\CourseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

class CourseController extends Controller
{
    public function __construct(
        protected CourseStorageService $storageService
    ) {}

    /**
     * List courses (metadata only, no full JSON).
     * Public: only published courses. Authenticated: all courses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Course::query();

        $user = $request->user();
        $adminRole = $request->attributes->get('admin_role');
        $isAdmin = $adminRole === 'admin';
        $isTeacher = $adminRole === 'teacher';

        if ($isAdmin) {
            // Admin: all courses, optionally filter by status or include trashed
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            if ($request->boolean('with_trashed')) {
                $query->withTrashed();
            }
        } elseif ($isTeacher) {
            // Teacher: only courses they created + courses assigned to their classrooms
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $teacherId = $user->id;
            $classroomCourseIds = Classroom::whereHas('teachers', fn ($q) => $q->where('users.id', $teacherId))
                ->with('courses:courses.id')
                ->get()
                ->pluck('courses.*.id')
                ->flatten()
                ->unique()
                ->all();

            $query->where(function ($q) use ($teacherId, $classroomCourseIds) {
                $q->where('created_by', $teacherId);
                if (!empty($classroomCourseIds)) {
                    $q->orWhereIn('id', $classroomCourseIds);
                }
            });
        } else {
            // Students and unauthenticated users: only published courses
            $query->published();
        }

        // Filter by language
        if ($request->has('language')) {
            $query->language($request->language);
        }

        // Filter by updated_since timestamp
        if ($request->has('updated_since')) {
            $query->updatedSince($request->updated_since);
        }

        // Admin/teacher gets enrollment stats on each course
        if ($isAdmin || $isTeacher) {
            $query->withCount('userCourses as users_count')
                ->withCount(['userCourses as completed_count' => fn ($q) => $q->where('status', 'completed')])
                ->withAvg('userCourses as avg_progress', 'progress_percent');
        }

        $courses = $query->orderBy('updated_at', 'desc')->get();

        // Round avg_progress for cleaner output
        if ($isAdmin || $isTeacher) {
            $courses->each(function ($course) {
                $course->avg_progress = $course->avg_progress !== null
                    ? round((float) $course->avg_progress, 1)
                    : null;
            });
        }

        return response()->json([
            'data' => $courses,
            'meta' => [
                'total' => $courses->count(),
            ],
        ]);
    }

    /**
     * Get a single course metadata.
     * Public: only published courses. Authenticated: any course.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $query = Course::query();

        // Public access: only published courses
        if (!$request->user() && !$request->attributes->get('is_admin')) {
            $query->published();
        }

        if ($request->attributes->get('admin_role')) {
            $query->withCount('userCourses as users_count')
                ->withCount(['userCourses as completed_count' => fn ($q) => $q->where('status', 'completed')])
                ->withAvg('userCourses as avg_progress', 'progress_percent');
        }

        $course = $query->findOrFail($id);

        if ($request->attributes->get('admin_role') && $course->avg_progress !== null) {
            $course->avg_progress = round((float) $course->avg_progress, 1);
        }

        return response()->json([
            'data' => $course,
        ]);
    }

    /**
     * Lookup a course by its unique alphanumeric access pin.
     * Returns published or private courses — private courses are
     * hidden from listings but accessible via PIN.
     */
    public function findByPin(Request $request, string $pin): JsonResponse
    {
        $normalized = strtoupper(trim($pin));

        if (strlen($normalized) !== 6 || !ctype_alnum($normalized)) {
            return response()->json([
                'message' => 'PIN musí mít 6 znaků (písmena a čísla)',
                'pin' => $pin,
            ], 422);
        }

        $course = Course::query()
            ->whereIn('status', ['published', 'private'])
            ->where('pin', $normalized)
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Kurz s tímto PINem nebyl nalezen',
                'pin' => $pin,
            ], 404);
        }

        return response()->json([
            'data' => $course,
        ]);
    }

    /**
     * Upload a course JSON file to R2 and create/update database record.
     * Requires authentication.
     */
    public function upload(UploadCourseRequest $request): JsonResponse
    {
        // validated() triggers validation rules, but we store the full payload
        // so fields like pin, header_image, etc. are preserved in the data column.
        $request->validated();
        $courseData = $request->all();

        // Extract metadata for database storage
        $metadata = $this->storageService->extractMetadata($courseData);

        // Check if course already exists
        $existingCourse = Course::where('course_id', $courseData['course_id'])->first();

        if ($existingCourse) {
            // Check version - must be higher than existing
            if ($courseData['version'] <= $existingCourse->version) {
                return response()->json([
                    'message' => 'Version must be higher than existing version',
                    'current_version' => $existingCourse->version,
                    'provided_version' => $courseData['version'],
                ], 422);
            }
        }

        // Upload to R2
        $uploadResult = $this->storageService->uploadCourse($courseData, $existingCourse);

        // Teacher ownership check for existing courses
        $user = $request->user();
        if ($existingCourse && $user && $user->isTeacher()
            && $existingCourse->created_by !== $user->id) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        // Create or update database record
        $courseAttrs = array_merge($metadata, [
            'data' => $courseData, // Store full JSON in database as backup
            'file_path' => $uploadResult['path'],
            'file_size' => $uploadResult['size'],
            'file_uploaded_at' => now(),
        ]);

        // Set created_by for new courses
        if (!$existingCourse && $user) {
            $courseAttrs['created_by'] = $user->id;
        }

        try {
            $course = Course::updateOrCreate(
                ['course_id' => $courseData['course_id']],
                $courseAttrs,
            );
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 7 || str_contains($e->getMessage(), '23505')) {
                // Unique constraint violation — most likely duplicate pin
                $pin = $courseAttrs['pin'] ?? null;
                return response()->json([
                    'message' => $pin
                        ? "Kurz s PINem {$pin} již existuje. Zvolte prosím jiný PIN."
                        : 'Kurz s tímto identifikátorem již existuje.',
                ], 422);
            }
            throw $e;
        }

        return response()->json([
            'message' => $existingCourse ? 'Course updated successfully' : 'Course created successfully',
            'data' => $course,
            'upload' => [
                'path' => $uploadResult['path'],
                'size' => $uploadResult['size'],
                'download_url' => $uploadResult['url'],
                'expires_at' => $uploadResult['expires_at'],
            ],
        ], $existingCourse ? 200 : 201);
    }

    /**
     * Get a signed download URL for a course.
     * Requires authentication. Tracks download in user_courses.
     */
    public function download(Request $request, int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        // Check if file exists in R2
        if (!$course->hasR2File()) {
            return response()->json([
                'message' => 'Course file not available for download',
                'course_id' => $course->course_id,
            ], 404);
        }

        // Track download in user_courses if authenticated
        $user = $request->user();
        if ($user) {
            UserCourse::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id, // Foreign key to courses.id
                ],
                [
                    'downloaded_version' => $course->version,
                    'downloaded_at' => now(),
                ]
            );
        }

        // If ?inline=true, return course JSON directly (avoids CORS on web)
        if ($request->boolean('inline')) {
            $content = Storage::disk('s3')->get($course->file_path);
            $courseData = json_decode($content, true);

            return response()->json([
                'data' => [
                    'course_id' => $course->course_id,
                    'version' => $course->version,
                    'file_size' => $course->file_size,
                    'course_data' => $courseData,
                ],
            ]);
        }

        // Generate signed URL
        $url = $this->storageService->getSignedUrl($course->file_path);
        $expiresAt = now()->addMinutes(60);

        return response()->json([
            'data' => [
                'course_id' => $course->course_id,
                'version' => $course->version,
                'download_url' => $url,
                'expires_at' => $expiresAt->toIso8601String(),
                'file_size' => $course->file_size,
            ],
        ]);
    }

    /**
     * Create a new course (legacy endpoint - use upload for R2 storage).
     * Requires authentication.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'course_id' => 'required|string|max:255|unique:courses',
            'version' => 'required|integer|min:1',
            'language' => 'required|string|max:10',
            'status' => 'sometimes|in:draft,locked,approved,published,private',
            'data' => 'required|array',
            'data.export_type' => 'required|in:course_v2,exercise_v2,quiz_v2',
        ]);

        $course = Course::create([
            'course_id' => $validated['course_id'],
            'name' => $validated['name'],
            'version' => $validated['version'],
            'language' => $validated['language'],
            'status' => $validated['status'] ?? 'draft',
            'data' => $validated['data'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Course created successfully',
            'data' => $course,
        ], 201);
    }

    /**
     * Update an existing course. Requires authentication.
     * Version is auto-incremented.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        // Teacher can only update own courses
        $user = $request->user();
        if ($user && $user->isTeacher() && $course->created_by !== $user->id) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'course_id' => 'sometimes|string|max:255|unique:courses,course_id,' . $id,
            'language' => 'sometimes|string|max:10',
            'status' => 'sometimes|in:draft,locked,approved,published,private',
            'description' => 'sometimes|nullable|string|max:500',
            'author' => 'sometimes|nullable|string|max:255',
            'emoji' => 'sometimes|nullable|string|max:10',
            'data' => 'sometimes|array',
            'data.export_type' => 'required_with:data|in:course_v2,exercise_v2,quiz_v2',
        ]);

        $course->update($validated);

        return response()->json([
            'message' => 'Course updated successfully',
            'data' => $course->fresh(),
        ]);
    }

    /**
     * Soft delete a course. Requires authentication.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        // Teacher can only delete own courses
        $user = $request->user();
        if ($user && $user->isTeacher() && $course->created_by !== $user->id) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully',
        ]);
    }

    /**
     * Restore a soft-deleted course.
     *
     * POST /courses/{id}/restore
     */
    public function restore(int $id): JsonResponse
    {
        $course = Course::withTrashed()->findOrFail($id);

        if (!$course->trashed()) {
            return response()->json([
                'message' => 'Course is not deleted',
            ], 422);
        }

        $course->restore();

        return response()->json([
            'data' => $course->fresh(),
        ]);
    }

    /**
     * Check for course updates since a given timestamp.
     * Returns lightweight list for sync purposes.
     * Public: only published courses. Authenticated: all courses.
     */
    public function checkUpdates(Request $request): JsonResponse
    {
        $request->validate([
            'updated_since' => 'sometimes|date',
        ]);

        $query = Course::query()
            ->select('id', 'course_id', 'version', 'status', 'file_size', 'updated_at');

        // Public access: only published courses
        if (!$request->user()) {
            $query->published();
        }

        // Filter by updated_since timestamp
        if ($request->has('updated_since')) {
            $query->updatedSince($request->updated_since);
        }

        $courses = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'data' => $courses,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Backfill course flags (pin, starts_with_quiz, only_once, logged_only) from stored JSON.
     * Admin-only, callable via API for production environments.
     *
     * POST /api/admin/courses/backfill
     */
    public function backfill(): JsonResponse
    {
        $courses = Course::whereNotNull('data')->get();
        $updated = [];

        foreach ($courses as $course) {
            $data = $course->data;
            if (!is_array($data)) {
                continue;
            }

            $changed = false;

            if (isset($data['pin']) && $data['pin'] !== '' && !$course->pin) {
                $course->pin = strtoupper((string) $data['pin']);
                $changed = true;
            }

            if (isset($data['starts_with_quiz'])) {
                $course->starts_with_quiz = (bool) $data['starts_with_quiz'];
                $changed = true;
            }

            if (isset($data['only_once'])) {
                $course->only_once = (bool) $data['only_once'];
                $changed = true;
            }

            if (isset($data['logged_only'])) {
                $course->logged_only = (bool) $data['logged_only'];
                $changed = true;
            }

            if (isset($data['quiz_evaluate'])) {
                $course->quiz_evaluate = (bool) $data['quiz_evaluate'];
                $changed = true;
            }

            if (isset($data['only_quiz'])) {
                $course->only_quiz = (bool) $data['only_quiz'];
                if ($course->only_quiz) {
                    $course->starts_with_quiz = true;
                }
                $changed = true;
            }

            if ($changed) {
                $course->saveQuietly();
                $updated[] = $course->course_id;
            }
        }

        return response()->json([
            'message' => 'Backfill complete',
            'updated' => $updated,
            'total_checked' => $courses->count(),
        ]);
    }
}
