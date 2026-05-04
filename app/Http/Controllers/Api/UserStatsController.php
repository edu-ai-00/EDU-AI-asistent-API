<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserStatsController extends Controller
{
    /**
     * Get the authenticated user's stats.
     * Creates default stats if none exist.
     */
    public function show(Request $request): JsonResponse
    {
        $stats = UserStats::getOrCreateForUser($request->user()->id);

        return response()->json([
            'stats' => $this->formatStats($stats),
        ]);
    }

    /**
     * Update the authenticated user's stats.
     * Accepts streak, XP, level, achievements, and daily XP tracking fields.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'streak_days' => 'sometimes|integer|min:0',
            'last_streak_date' => 'sometimes|nullable|date',
            'xp_points' => 'sometimes|integer|min:0',
            'level' => 'sometimes|integer|min:1',
            'achievements_count' => 'sometimes|integer|min:0',
            'daily_xp_date' => 'sometimes|nullable|date',
            'daily_xp_amount' => 'sometimes|integer|min:0',
        ]);

        $stats = UserStats::getOrCreateForUser($request->user()->id);
        $stats->update($validated);

        return response()->json([
            'message' => 'Stats updated',
            'stats' => $this->formatStats($stats->fresh()),
        ]);
    }

    /**
     * Format stats for API response.
     */
    private function formatStats(UserStats $stats): array
    {
        return [
            'id' => $stats->id,
            'user_id' => $stats->user_id,
            'level' => $stats->level,
            'xp_points' => $stats->xp_points,
            'courses_count' => $stats->courses_count,
            'streak_days' => $stats->streak_days,
            'achievements_count' => $stats->achievements_count,
            'last_streak_date' => $stats->last_streak_date?->toDateString(),
            'daily_xp_date' => $stats->daily_xp_date?->toDateString(),
            'daily_xp_amount' => $stats->daily_xp_amount ?? 0,
            'created_at' => $stats->created_at->toIso8601String(),
            'updated_at' => $stats->updated_at->toIso8601String(),
        ];
    }
}
