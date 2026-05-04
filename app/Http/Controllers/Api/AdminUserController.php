<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseSkillConfig;
use App\Models\User;
use App\Models\EloInteraction;
use App\Models\UserEloProfile;
use App\Services\SkillComputationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    /**
     * List users with summary data, server-side filtering & pagination.
     *
     * Query params:
     *   search         - filter by name or email (LIKE)
     *   classroom_ids  - array of classroom IDs (OR)
     *   course_ids     - array of course_id strings (user has at least one)
     *   user_type      - "registered" | "guest" | omit for all
     *   sort           - name|classroom|level|courses|xp|last_active (default: last_active)
     *   dir            - asc|desc (default: desc)
     *   per_page       - items per page (default: 50, max: 200)
     *   page           - page number (default: 1)
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['stats', 'classroom:id,name'])
            ->withCount('courses')
            ->withCount(['courses as completed_count' => function ($q) {
                $q->where('status', 'completed');
            }]);

        // Teacher scoping: only students in their classrooms
        if ($request->attributes->get('admin_role') === 'teacher') {
            $classroomIds = $request->user()->teachingClassrooms()->pluck('classrooms.id');
            $query->whereIn('classroom_id', $classroomIds);
        }

        // ── Filters ──────────────────────────────────────────────────────────

        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('classroom_ids')) {
            $query->whereIn('classroom_id', (array) $request->input('classroom_ids'));
        }

        if ($request->filled('course_ids')) {
            $courseIds = (array) $request->input('course_ids');
            $query->whereHas('courses', function ($q) use ($courseIds) {
                $q->whereHas('course', function ($q2) use ($courseIds) {
                    $q2->whereIn('course_id', $courseIds);
                });
            });
        }

        if ($request->filled('user_type')) {
            $userType = $request->input('user_type');
            if ($userType === 'guest') {
                $query->where('is_guest', true);
            } elseif ($userType === 'registered') {
                $query->where('is_guest', false);
            }
        }

        // ── Sorting ──────────────────────────────────────────────────────────

        $sortKey = $request->input('sort', 'last_active');
        $sortDir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        // Build last-activity subquery (joined for sorting & output)
        $lastActivitySub = DB::query()
            ->fromSub(function ($sub) {
                $sub->select('user_id', DB::raw('MAX(updated_at) as last_active'))
                    ->from('user_courses')
                    ->groupBy('user_id')
                    ->unionAll(
                        DB::table('user_progress')
                            ->select('user_id', DB::raw('MAX(updated_at) as last_active'))
                            ->groupBy('user_id')
                    )
                    ->unionAll(
                        DB::table('quiz_attempts')
                            ->select('user_id', DB::raw('MAX(created_at) as last_active'))
                            ->groupBy('user_id')
                    )
                    ->unionAll(
                        DB::table('elo_interactions')
                            ->select('user_id', DB::raw('MAX(created_at) as last_active'))
                            ->groupBy('user_id')
                    )
                    ->unionAll(
                        DB::table('bookmarks')
                            ->select('user_id', DB::raw('MAX(updated_at) as last_active'))
                            ->groupBy('user_id')
                    )
                    ->unionAll(
                        DB::table('user_achievements')
                            ->select('user_id', DB::raw('MAX(earned_at) as last_active'))
                            ->groupBy('user_id')
                    );
            }, 'activities')
            ->select('user_id', DB::raw('MAX(last_active) as last_active'))
            ->groupBy('user_id');

        $query->leftJoinSub($lastActivitySub, 'la', 'la.user_id', '=', 'users.id');
        $query->addSelect('la.last_active as last_active_at_raw');

        switch ($sortKey) {
            case 'name':
                $query->orderBy('users.name', $sortDir);
                break;
            case 'classroom':
                $query->leftJoin('classrooms as cls_sort', 'cls_sort.id', '=', 'users.classroom_id')
                    ->orderBy('cls_sort.name', $sortDir);
                break;
            case 'level':
                $query->leftJoin('user_stats as us_sort', 'us_sort.user_id', '=', 'users.id')
                    ->orderByRaw("COALESCE(us_sort.level, 0) {$sortDir}");
                break;
            case 'courses':
                $query->orderBy('courses_count', $sortDir);
                break;
            case 'xp':
                $query->leftJoin('user_stats as ux_sort', 'ux_sort.user_id', '=', 'users.id')
                    ->orderByRaw("COALESCE(ux_sort.xp_points, 0) {$sortDir}");
                break;
            case 'last_active':
            default:
                $query->orderByRaw("la.last_active {$sortDir} NULLS LAST");
                break;
        }

        // Secondary sort for determinism
        $query->orderBy('users.id', 'desc');

        // ── Paginate ─────────────────────────────────────────────────────────

        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginator = $query->paginate($perPage, ['users.*']);

        $data = collect($paginator->items())->map(function ($user) {
            $lastActive = $user->last_active_at_raw;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'student',
                'login_code' => $user->login_code,
                'is_guest' => $user->is_guest,
                'device_id' => $user->device_id,
                'avatar_index' => $user->avatar_index,
                'selected_subjects' => $user->selected_subjects,
                'classroom_id' => $user->classroom_id,
                'classroom_name' => $user->classroom?->name,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'courses_count' => $user->courses_count,
                'completed_count' => $user->completed_count,
                'last_active_at' => $lastActive
                    ? Carbon::parse($lastActive)->toIso8601String()
                    : null,
                'stats' => $user->stats ? [
                    'level' => $user->stats->level,
                    'xp_points' => $user->stats->xp_points,
                    'streak_days' => $user->stats->streak_days,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Get detailed info for a single user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::with(['stats', 'courses.course', 'progress', 'quizAttempts', 'achievements', 'bookmarks', 'classroom:id,name'])->findOrFail($id);

        // Teacher scoping: only students in their classrooms
        if ($request->attributes->get('admin_role') === 'teacher') {
            $classroomIds = $request->user()->teachingClassrooms()->pluck('classrooms.id');
            if (!$classroomIds->contains($user->classroom_id)) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        // Get recent sessions for this user
        $sessions = DB::table('sessions')
            ->where('user_id', $id)
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get(['ip_address', 'user_agent', 'last_activity']);

        $sessionsData = $sessions->map(function ($session) {
            return [
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            ];
        });

        $coursesData = $user->courses->map(function ($uc) {
            return [
                'id' => $uc->id,
                'course_name' => $uc->course?->name,
                'course_id' => $uc->course?->course_id,
                'only_quiz' => (bool) ($uc->course?->only_quiz ?? false),
                'status' => $uc->status,
                'progress_percent' => $uc->progress_percent,
                'completed_lessons' => $uc->completed_lessons,
                'total_lessons' => $uc->total_lessons,
                'current_lesson_index' => $uc->current_lesson_index,
                'time_spent_seconds' => $uc->time_spent_seconds,
                'progress_data' => $uc->progress_data,
                'downloaded_at' => $uc->downloaded_at?->toIso8601String(),
                'started_at' => $uc->started_at?->toIso8601String(),
                'completed_at' => $uc->completed_at?->toIso8601String(),
                'updated_at' => $uc->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'student',
                'login_code' => $user->login_code,
                'is_guest' => $user->is_guest,
                'device_id' => $user->device_id,
                'avatar_index' => $user->avatar_index,
                'selected_subjects' => $user->selected_subjects,
                'classroom_id' => $user->classroom_id,
                'classroom_name' => $user->classroom?->name,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'stats' => $user->stats ? [
                    'level' => $user->stats->level,
                    'xp_points' => $user->stats->xp_points,
                    'streak_days' => $user->stats->streak_days,
                    'achievements_count' => $user->stats->achievements_count,
                    'courses_count' => $user->stats->courses_count,
                    'last_streak_date' => $user->stats->last_streak_date?->toDateString(),
                    'daily_xp_date' => $user->stats->daily_xp_date?->toDateString(),
                    'daily_xp_amount' => $user->stats->daily_xp_amount ?? 0,
                ] : null,
                'courses' => $coursesData,
                'progress' => $user->progress->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'course_id' => $p->course_id,
                        'lesson_id' => $p->lesson_id,
                        'progress_percent' => $p->progress_percent,
                        'is_completed' => $p->is_completed,
                        'last_position' => $p->last_position,
                        'time_spent_seconds' => $p->time_spent_seconds,
                        'started_at' => $p->started_at?->toIso8601String(),
                        'completed_at' => $p->completed_at?->toIso8601String(),
                        'progress_data' => $p->progress_data,
                        'updated_at' => $p->updated_at->toIso8601String(),
                    ];
                }),
                'quiz_attempts' => $user->quizAttempts
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(function ($a) {
                        $answers = $a->answers;
                        if (is_string($answers)) {
                            $answers = json_decode($answers, true) ?? [];
                        }

                        return [
                            'id' => $a->id,
                            'course_id' => $a->course_id,
                            'total_questions' => $a->total_questions,
                            'correct_answers' => $a->correct_answers,
                            'score_percent' => $a->score_percent,
                            'time_spent_seconds' => $a->time_spent_seconds,
                            'started_at' => $a->started_at?->toIso8601String(),
                            'completed_at' => $a->completed_at?->toIso8601String(),
                            'answers' => $answers ?? [],
                            'created_at' => $a->created_at->toIso8601String(),
                        ];
                    }),
                'achievements' => $user->achievements
                    ->sortByDesc('earned_at')
                    ->values()
                    ->map(fn ($a) => [
                        'id' => $a->achievement_id,
                        'earned_at' => $a->earned_at->toIso8601String(),
                    ]),
                'bookmarks' => $user->bookmarks
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn ($b) => [
                        'id' => $b->id,
                        'course_id' => $b->course_id,
                        'lesson_id' => $b->lesson_id,
                        'block_id' => $b->block_id,
                        'note' => $b->note,
                        'created_at' => $b->created_at->toIso8601String(),
                    ]),
                'sessions' => $sessionsData,
            ],
        ]);
    }

    /**
     * Update a user's basic info (admin).
     *
     * PUT /api/admin/users/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Teacher scoping: only students in their classrooms
        if ($request->attributes->get('admin_role') === 'teacher') {
            $classroomIds = $request->user()->teachingClassrooms()->pluck('classrooms.id');
            if (!$classroomIds->contains($user->classroom_id)) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'classroom_id' => 'sometimes|nullable|integer|exists:classrooms,id',
        ];

        // Only admins can change roles
        if ($request->attributes->get('admin_role') === 'admin') {
            $rules['role'] = 'sometimes|in:student,teacher,admin';
        }

        $validated = $request->validate($rules);

        // Prevent admin from demoting themselves
        if (isset($validated['role']) && $user->id === $request->user()?->id && $validated['role'] !== 'admin') {
            return response()->json(['message' => 'Nemůžete změnit svou vlastní roli.'], 422);
        }

        $user->update($validated);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'student',
                'classroom_id' => $user->classroom_id,
            ],
        ]);
    }

    /**
     * Compute skills for a single user in a given course.
     *
     * GET /api/admin/users/{userId}/skills?course_id=X
     */
    public function skills(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'course_id' => 'required',
        ]);

        $user = User::findOrFail($userId);

        // Teacher scoping: only students in their classrooms
        if ($request->attributes->get('admin_role') === 'teacher') {
            $classroomIds = $request->user()->teachingClassrooms()->pluck('classrooms.id');
            if (!$classroomIds->contains($user->classroom_id)) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        // Accept both numeric course DB id and string course_id
        $input = $request->input('course_id');
        $course = is_numeric($input)
            ? \App\Models\Course::find((int) $input)
            : \App\Models\Course::where('course_id', $input)->first();

        if (!$course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $courseId = $course->id;

        // Get config (or default)
        $config = CourseSkillConfig::where('course_id', $courseId)->first();
        $formulaJson = $config?->formula_json ?? SkillComputationService::defaultGpfFormula();
        $confidenceC = $config?->confidence_c ?? 2.5;
        $minCount = $config?->min_count ?? 10;
        $scaleMin = $config?->display_scale_min ?? 1.0;
        $scaleMax = $config?->display_scale_max ?? 10.0;

        $skillNames = array_keys($formulaJson);

        $aggregated = $request->boolean('aggregated', true);

        // Load user's ELO profile
        $profile = UserEloProfile::where('user_id', $userId)->first();
        $profilElo = $profile?->profil_elo;

        // For "pouze kurz" mode: count only interactions from this course
        if ($aggregated) {
            $profilPocet = $profile?->profil_pocet;
        } else {
            $courseOnlyPocet = array_fill(0, 35, 0);
            $interactions = EloInteraction::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->get();
            foreach ($interactions as $interaction) {
                $indices = $interaction->updated_indices ?? [];
                foreach ($indices as $dimIdx) {
                    if ($dimIdx >= 0 && $dimIdx < 35) {
                        $courseOnlyPocet[$dimIdx]++;
                    }
                }
            }
            $profilPocet = $courseOnlyPocet;
        }

        if (!$profilElo) {
            // No ELO data: return skills with null levels
            $skills = array_map(fn (string $name) => [
                'name' => $name,
                'level' => null,
                'interval_low' => null,
                'interval_high' => null,
                'confidence_label' => null,
                'included_count' => 0,
                'excluded_count' => 0,
                'median_count' => null,
                'message' => 'Nedostatek dat',
            ], $skillNames);

            return response()->json([
                'data' => [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'skill_names' => $skillNames,
                    'skills' => $skills,
                    'has_data' => false,
                ],
            ]);
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

        return response()->json([
            'data' => [
                'user_id' => $userId,
                'course_id' => $courseId,
                'skill_names' => $skillNames,
                'skills' => $skills,
                'has_data' => true,
            ],
        ]);
    }
}
