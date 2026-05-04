<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserEloProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EloProfileController extends Controller
{
    /**
     * Get the authenticated user's ELO profile.
     * Returns nulls for first-time users.
     *
     * GET /api/user/elo-profile
     */
    public function show(Request $request): JsonResponse
    {
        $profile = UserEloProfile::forUser($request->user()->id)->first();

        return response()->json([
            'profil_elo' => $profile?->profil_elo,
            'profil_pocet' => $profile?->profil_pocet,
            'updated_at' => $profile?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Update the authenticated user's ELO profile.
     * Creates or updates (upsert on user_id).
     *
     * PUT /api/user/elo-profile
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profil_elo' => 'nullable|array',
            'profil_elo.*' => 'nullable|numeric',
            'profil_pocet' => 'nullable|array',
            'profil_pocet.*' => 'nullable|integer',
        ]);

        $profile = UserEloProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'profil_elo' => $validated['profil_elo'] ?? null,
                'profil_pocet' => $validated['profil_pocet'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'ELO profile updated',
            'profil_elo' => $profile->profil_elo,
            'profil_pocet' => $profile->profil_pocet,
            'updated_at' => $profile->updated_at->toIso8601String(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Admin endpoints
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Get ELO profile for a specific user (admin).
     *
     * GET /api/admin/elo/profile/{userId}
     */
    public function adminByUser(int $userId): JsonResponse
    {
        $profile = UserEloProfile::forUser($userId)->first();

        return response()->json([
            'data' => [
                'user_id' => $userId,
                'profil_elo' => $profile?->profil_elo,
                'profil_pocet' => $profile?->profil_pocet,
                'updated_at' => $profile?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * List all ELO profiles (admin).
     *
     * GET /api/admin/elo/profiles
     */
    public function adminIndex(): JsonResponse
    {
        $profiles = UserEloProfile::with('user:id,name,email')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $profiles->map(fn ($p) => [
                'user' => $p->user ? [
                    'id' => $p->user->id,
                    'name' => $p->user->name,
                    'email' => $p->user->email,
                ] : null,
                'profil_elo' => $p->profil_elo,
                'profil_pocet' => $p->profil_pocet,
                'updated_at' => $p->updated_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $profiles->count(),
            ],
        ]);
    }
}
