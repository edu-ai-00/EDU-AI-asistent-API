<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTeamController extends Controller
{
    /**
     * List all admin and teacher accounts.
     *
     * GET /api/admin/team
     */
    public function index(): JsonResponse
    {
        $users = User::whereIn('role', ['admin', 'teacher'])
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at']);

        return response()->json([
            'data' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'created_at' => $u->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create an admin or teacher account.
     *
     * POST /api/admin/team
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'role' => 'required|in:admin,teacher',
        ]);

        $email = strtolower(trim($validated['email']));
        $existing = User::where('email', $email)->first();

        if ($existing && in_array($existing->role, ['admin', 'teacher'])) {
            return response()->json([
                'message' => 'This user is already a team member.',
            ], 422);
        }

        if ($existing) {
            // Promote existing student to teacher/admin
            $existing->update([
                'name' => $validated['name'],
                'role' => $validated['role'],
            ]);
            $user = $existing;
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $email,
                'role' => $validated['role'],
                'email_verified_at' => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update name or role of a team member.
     *
     * PUT /api/admin/team/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::whereIn('role', ['admin', 'teacher'])->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'role' => 'sometimes|in:admin,teacher',
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        // Prevent self-demotion
        if (isset($validated['role'])
            && $request->user()->id === $user->id
            && $validated['role'] !== $user->role) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        $user->update($validated);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Remove a team member (set role back to student).
     * Prevents self-demotion.
     *
     * DELETE /api/admin/team/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::whereIn('role', ['admin', 'teacher'])->findOrFail($id);

        // Prevent self-demotion
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot remove yourself from the team.',
            ], 422);
        }

        $user->update(['role' => 'student']);

        return response()->json([
            'message' => 'Team member removed successfully.',
        ]);
    }
}
