<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Schools as organisational units (BR-8QAWKP).
 *
 * Admin-only end to end: the client asked that assigning a teacher to a
 * school be an admin decision ("jen admin"), the same rule that already
 * governs the classroom roster in AdminClassroomController::update(). The
 * routes are registered in the admin-only group, so there is no per-method
 * role check here — unlike the classroom controller, nothing in this file is
 * reachable by a teacher.
 */
class AdminSchoolController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List schools with their teachers and class counts.
     *
     * GET /api/admin/schools
     */
    public function index(): JsonResponse
    {
        $schools = School::withCount('classrooms')
            ->with('teachers:id,name,email,role')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $schools->map(fn ($s) => $this->format($s)),
        ]);
    }

    /**
     * GET /api/admin/schools/{id}
     */
    public function show(int $id): JsonResponse
    {
        $school = School::withCount('classrooms')
            ->with('teachers:id,name,email,role', 'classrooms:id,name,school_id')
            ->findOrFail($id);

        return response()->json([
            'data' => $this->format($school) + [
                'classrooms' => $school->classrooms->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/admin/schools
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'identifier' => 'sometimes|nullable|string|max:50',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
        ]);

        $school = School::create([
            'name' => $validated['name'],
            'address' => $validated['address'] ?? null,
            'identifier' => $validated['identifier'] ?? null,
        ]);

        if (!empty($validated['teacher_ids'])) {
            $school->teachers()->sync($this->onlyTeachers($validated['teacher_ids']));
        }

        $this->audit->log('school.created', $school, diff: AuditLogger::created($school, ['name', 'address', 'identifier']) + array_filter([
            'teacher_ids' => AuditLogger::idSetChange([], $school->teachers()->pluck('users.id')),
        ]));

        return response()->json([
            'data' => $this->format($school->loadCount('classrooms')->load('teachers:id,name,email,role')),
        ], 201);
    }

    /**
     * PUT /api/admin/schools/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $school = School::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'identifier' => 'sometimes|nullable|string|max:50',
            'teacher_ids' => 'sometimes|array',
            'teacher_ids.*' => 'integer|exists:users,id',
        ]);

        $school->update(array_intersect_key($validated, array_flip(['name', 'address', 'identifier'])));

        if ($diff = AuditLogger::changes($school)) {
            $this->audit->log('school.updated', $school, diff: $diff);
        }

        if (array_key_exists('teacher_ids', $validated)) {
            $before = $school->teachers()->pluck('users.id');
            $school->teachers()->sync($this->onlyTeachers($validated['teacher_ids']));

            if ($change = AuditLogger::idSetChange($before, $school->teachers()->pluck('users.id'))) {
                $this->audit->log('school.teachers_changed', $school, diff: ['teacher_ids' => $change]);
            }
        }

        return response()->json([
            'data' => $this->format($school->loadCount('classrooms')->load('teachers:id,name,email,role')),
        ]);
    }

    /**
     * Delete a school.
     *
     * Its classrooms survive — classrooms.school_id is nullOnDelete, so they
     * fall back to being unfiled rather than disappearing with the school.
     *
     * DELETE /api/admin/schools/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $school = School::findOrFail($id);
        $classroomIds = $school->classrooms()->pluck('id')->all();

        $school->delete();

        $this->audit->log('school.deleted', $school, meta: [
            'name' => $school->name,
            'unfiled_classroom_ids' => $classroomIds,
        ]);

        return response()->json(['message' => 'School deleted.']);
    }

    /**
     * Set the classrooms belonging to a school in one shot.
     *
     * A sync rather than an attach: the admin screen edits the whole list, and
     * classrooms left out are unfiled (school_id = null), not deleted.
     *
     * PUT /api/admin/schools/{id}/classrooms
     */
    public function syncClassrooms(Request $request, int $id): JsonResponse
    {
        $school = School::findOrFail($id);

        $validated = $request->validate([
            'classroom_ids' => 'present|array',
            'classroom_ids.*' => 'integer|exists:classrooms,id',
        ]);

        $before = $school->classrooms()->pluck('id');

        $school->classrooms()->update(['school_id' => null]);

        if (!empty($validated['classroom_ids'])) {
            \App\Models\Classroom::whereIn('id', $validated['classroom_ids'])
                ->update(['school_id' => $school->id]);
        }

        if ($change = AuditLogger::idSetChange($before, $school->classrooms()->pluck('id'))) {
            $this->audit->log('school.classrooms_changed', $school, diff: ['classroom_ids' => $change]);
        }

        return response()->json([
            'data' => $this->format($school->loadCount('classrooms')->load('teachers:id,name,email,role')),
        ]);
    }

    /**
     * Drop ids that are not teacher accounts.
     *
     * `exists:users,id` only proves the row exists; without this an admin
     * could file a student under a school and hand them a colleague's class
     * list the moment their role ever changed.
     */
    private function onlyTeachers(array $ids): array
    {
        return User::whereIn('id', $ids)
            ->whereIn('role', ['admin', 'teacher'])
            ->pluck('id')
            ->all();
    }

    private function format(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'address' => $school->address,
            'identifier' => $school->identifier,
            'classrooms_count' => $school->classrooms_count ?? 0,
            // role: the school dialog removes a teacher by demoting them
            // (BR-9Z59Z9), which it must never do to an admin filed here.
            'teachers' => $school->teachers->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
                'role' => $t->role,
            ]),
            'created_at' => $school->created_at->toIso8601String(),
        ];
    }
}
