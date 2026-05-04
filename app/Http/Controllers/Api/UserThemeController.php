<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserThemeController extends Controller
{
    /**
     * List all custom themes for the authenticated user.
     *
     * GET /api/user/themes
     */
    public function index(Request $request): JsonResponse
    {
        $themes = UserTheme::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $themes->map(fn ($t) => $this->formatTheme($t)),
        ]);
    }

    /**
     * Create or update a custom theme (upsert by name).
     *
     * POST /api/user/themes
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'theme_config' => 'required|array',
            'theme_config.colors' => 'required|array',
            'theme_config.typography' => 'required|array',
            'theme_config.radii' => 'required|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $userId = $request->user()->id;

        $theme = UserTheme::updateOrCreate(
            [
                'user_id' => $userId,
                'name' => $validated['name'],
            ],
            [
                'theme_config' => $validated['theme_config'],
                'is_active' => $validated['is_active'] ?? false,
            ]
        );

        // If this theme is active, deactivate others.
        if ($theme->is_active) {
            UserTheme::where('user_id', $userId)
                ->where('id', '!=', $theme->id)
                ->update(['is_active' => false]);
        }

        return response()->json(
            $this->formatTheme($theme),
            $theme->wasRecentlyCreated ? 201 : 200
        );
    }

    /**
     * Bulk sync themes from the app (offline-first pattern).
     *
     * POST /api/user/themes/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'themes' => 'required|array',
            'themes.*.name' => 'required|string|max:255',
            'themes.*.theme_config' => 'required|array',
            'themes.*.is_active' => 'sometimes|boolean',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['themes'] as $entry) {
            $theme = UserTheme::updateOrCreate(
                [
                    'user_id' => $userId,
                    'name' => $entry['name'],
                ],
                [
                    'theme_config' => $entry['theme_config'],
                    'is_active' => $entry['is_active'] ?? false,
                ]
            );

            $results[] = $this->formatTheme($theme);
        }

        // Ensure at most one active theme.
        $activeThemes = UserTheme::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($activeThemes->count() > 1) {
            UserTheme::where('user_id', $userId)
                ->where('is_active', true)
                ->where('id', '!=', $activeThemes->first()->id)
                ->update(['is_active' => false]);
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * Activate a theme (deactivates all others).
     *
     * POST /api/user/themes/{id}/activate
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $theme = UserTheme::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Deactivate all, then activate this one.
        UserTheme::where('user_id', $request->user()->id)
            ->update(['is_active' => false]);

        $theme->update(['is_active' => true]);

        return response()->json($this->formatTheme($theme->fresh()));
    }

    /**
     * Update an existing theme.
     *
     * PUT /api/user/themes/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $theme = UserTheme::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'theme_config' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $theme->update($validated);

        if ($theme->is_active) {
            UserTheme::where('user_id', $request->user()->id)
                ->where('id', '!=', $theme->id)
                ->update(['is_active' => false]);
        }

        return response()->json($this->formatTheme($theme));
    }

    /**
     * Delete a custom theme.
     *
     * DELETE /api/user/themes/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $theme = UserTheme::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $theme->delete();

        return response()->json([
            'message' => 'Theme deleted',
        ]);
    }

    /**
     * Format a theme for the API response.
     */
    private function formatTheme(UserTheme $theme): array
    {
        return [
            'id' => $theme->id,
            'name' => $theme->name,
            'theme_config' => $theme->theme_config,
            'is_active' => $theme->is_active,
            'created_at' => $theme->created_at->toIso8601String(),
            'updated_at' => $theme->updated_at->toIso8601String(),
        ];
    }
}
