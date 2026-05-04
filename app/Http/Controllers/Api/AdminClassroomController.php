<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseSkillConfig;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Services\SkillComputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminClassroomController extends Controller
{
    /**
     * Create a classroom with pre-generated students.
     *
     * POST /api/admin/classrooms
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'students' => 'required|array|min:1',
            'students.*.name' => 'required|string|max:255',
            'students.*.login_code' => 'required|string|size:6|alpha_num|distinct|unique:users,login_code',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
        ]);

        $user = $request->user();

        $classroom = DB::transaction(function () use ($validated, $user) {
            $classroom = Classroom::create([
                'name' => $validated['name'],
            ]);

            // Attach teachers — default to current user if authenticated, otherwise none
            $teacherIds = $validated['teacher_ids'] ?? ($user ? [$user->id] : []);
            if (!empty($teacherIds)) {
                $classroom->teachers()->attach($teacherIds);
            }

            foreach ($validated['students'] as $studentData) {
                User::create([
                    'name' => $studentData['name'],
                    'login_code' => strtoupper($studentData['login_code']),
                    'classroom_id' => $classroom->id,
                ]);
            }

            return $classroom;
        });

        $classroom->load('students:id,name,login_code,classroom_id', 'teachers:id,name,email');

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ], 201);
    }

    /**
     * List classrooms with student counts.
     * Teacher: only own classrooms. Admin: all.
     *
     * GET /api/admin/classrooms
     */
    public function index(Request $request): JsonResponse
    {
        $query = Classroom::withCount('students')->with('teachers:id,name');

        // Teacher scoping: only classrooms where they are a teacher
        if ($request->attributes->get('admin_role') === 'teacher') {
            $query->whereHas('teachers', fn ($q) => $q->where('users.id', $request->user()->id));
        }

        $classrooms = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $classrooms->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'students_count' => $c->students_count,
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
        $classroom = Classroom::with([
            'students:id,name,login_code,classroom_id',
            'teachers:id,name,email',
            'courses:courses.id,course_id,name,emoji',
        ])->findOrFail($id);

        // Teacher can only view classrooms they teach
        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ]);
    }

    /**
     * Update a classroom (name, teachers).
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

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
        ]);

        if (isset($validated['name'])) {
            $classroom->update(['name' => $validated['name']]);
        }

        if (isset($validated['teacher_ids'])) {
            $classroom->teachers()->sync($validated['teacher_ids']);
        }

        $classroom->load('students:id,name,login_code,classroom_id', 'teachers:id,name,email', 'courses:courses.id,course_id,name,emoji');

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

        DB::transaction(function () use ($classroom, $validated) {
            $newStudentIds = [];

            foreach ($validated['students'] as $studentData) {
                $student = User::create([
                    'name' => $studentData['name'],
                    'login_code' => strtoupper($studentData['login_code']),
                    'classroom_id' => $classroom->id,
                ]);
                $newStudentIds[] = $student->id;
            }

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

        $classroom->load('students:id,name,login_code,classroom_id', 'teachers:id,name,email', 'courses:courses.id,course_id,name,emoji');

        return response()->json([
            'data' => $this->formatClassroomDetail($classroom),
        ], 201);
    }

    /**
     * Delete a classroom and its student accounts.
     * Teacher: ownership check. Admin: any.
     *
     * DELETE /api/admin/classrooms/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        // Teacher can only delete classrooms they teach
        if ($request->attributes->get('admin_role') === 'teacher'
            && !$classroom->hasTeacher($request->user()->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        DB::transaction(function () use ($classroom) {
            // Delete all student users belonging to this classroom
            User::where('classroom_id', $classroom->id)->delete();
            $classroom->delete();
        });

        return response()->json([
            'message' => 'Classroom deleted successfully',
        ]);
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
            $classroom->courses()->syncWithoutDetaching($validated['course_ids']);

            // Bulk-enroll all current students
            $studentIds = $classroom->students()->pluck('id');
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

        $classroom->courses()->detach($courseId);

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

        // Get config (or default)
        $config = CourseSkillConfig::where('course_id', $courseId)->first();
        $formulaJson = $config?->formula_json ?? SkillComputationService::defaultGpfFormula();
        $confidenceC = $config?->confidence_c ?? 2.5;
        $minCount = $config?->min_count ?? 10;
        $scaleMin = $config?->display_scale_min ?? 1.0;
        $scaleMax = $config?->display_scale_max ?? 10.0;

        // Get student IDs in this classroom
        $classroomStudentIds = $classroom->students()->pluck('id');

        // Get enrollments for this course filtered to classroom students
        $enrollments = UserCourse::where('course_id', $courseId)
            ->whereIn('user_id', $classroomStudentIds)
            ->with('user:id,name,email,classroom_id')
            ->get();

        $studentIds = $enrollments->pluck('user_id')->all();

        // Load ELO profiles
        $profiles = UserEloProfile::whereIn('user_id', $studentIds)
            ->get()
            ->keyBy('user_id');

        // For "pouze kurz" mode: build per-user task counts from this course's interactions only
        $courseOnlyPocet = [];
        if (!$aggregated) {
            $interactions = EloInteraction::whereIn('user_id', $studentIds)
                ->where('course_id', $courseId)
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
                        'classroom_id' => $enrollment->user->classroom_id,
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
                    'classroom_id' => $enrollment->user->classroom_id,
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

        $studentIds = $classroom->students()->pluck('id');

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
