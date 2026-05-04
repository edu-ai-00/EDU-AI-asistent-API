<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentFsrsProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FsrsProfileController extends Controller
{
    /**
     * Get the FSRS profile for the authenticated user.
     * Returns null data if no profile exists yet.
     *
     * GET /api/user/fsrs-profile
     */
    public function show(Request $request): JsonResponse
    {
        $profile = StudentFsrsProfile::where('user_id', $request->user()->id)->first();

        if (!$profile) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $this->formatProfile($profile),
        ]);
    }

    /**
     * Create or update the FSRS profile for the authenticated user.
     *
     * PUT /api/user/fsrs-profile
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'desired_retention' => 'nullable|numeric|min:0.5|max:0.99',
            'maximum_interval' => 'nullable|integer|min:1|max:36500',
            'enable_fuzz' => 'nullable|boolean',
            'enable_short_term' => 'nullable|boolean',
            'learning_steps' => 'nullable|array',
            'learning_steps.*' => 'nullable|string',
            'relearning_steps' => 'nullable|array',
            'relearning_steps.*' => 'nullable|string',
            'fsrs_weights' => 'nullable|array',
            'profile_version' => 'nullable|integer|min:1',
            'daily_new_limit' => 'nullable|integer|min:0|max:9999',
            'daily_review_limit' => 'nullable|integer|min:0|max:9999',
            'session_expiration_sec' => 'nullable|integer|min:60',
        ]);

        $profile = StudentFsrsProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            array_filter($validated, fn ($v) => $v !== null)
        );

        return response()->json([
            'data' => $this->formatProfile($profile),
            'status' => $profile->wasRecentlyCreated ? 'created' : 'updated',
        ], $profile->wasRecentlyCreated ? 201 : 200);
    }

    private function formatProfile(StudentFsrsProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'desired_retention' => $profile->desired_retention,
            'maximum_interval' => $profile->maximum_interval,
            'enable_fuzz' => $profile->enable_fuzz,
            'enable_short_term' => $profile->enable_short_term,
            'learning_steps' => $profile->learning_steps,
            'relearning_steps' => $profile->relearning_steps,
            'fsrs_weights' => $profile->fsrs_weights,
            'profile_version' => $profile->profile_version,
            'daily_new_limit' => $profile->daily_new_limit,
            'daily_review_limit' => $profile->daily_review_limit,
            'session_expiration_sec' => $profile->session_expiration_sec,
            'created_at' => $profile->created_at->toIso8601String(),
            'updated_at' => $profile->updated_at->toIso8601String(),
        ];
    }
}
