<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAchievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAchievementController extends Controller
{
    /**
     * Get the authenticated user's earned achievements.
     *
     * GET /api/user/achievements
     */
    public function index(Request $request): JsonResponse
    {
        $achievements = UserAchievement::where('user_id', $request->user()->id)
            ->orderBy('earned_at', 'desc')
            ->get();

        return response()->json([
            'achievements' => $achievements->map(fn ($a) => [
                'id' => $a->achievement_id,
                'earned_at' => $a->earned_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Store newly earned achievements (offline-first sync).
     *
     * POST /api/user/achievements
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'achievements' => 'required|array|min:1',
            'achievements.*.id' => 'required|string|max:255',
            'achievements.*.earned_at' => 'required|date',
        ]);

        $userId = $request->user()->id;
        $newCount = 0;

        foreach ($validated['achievements'] as $achievement) {
            $created = UserAchievement::firstOrCreate(
                [
                    'user_id' => $userId,
                    'achievement_id' => $achievement['id'],
                ],
                [
                    'earned_at' => $achievement['earned_at'],
                ]
            );

            if ($created->wasRecentlyCreated) {
                $newCount++;
            }
        }

        return response()->json([
            'message' => 'ok',
            'new_count' => $newCount,
        ]);
    }
}
