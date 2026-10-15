<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shared language catalogue (BR-AYACEK).
 *
 * Reading is public — the admin editor's language picker and the app both need
 * it before anyone is authenticated. Writing is admin-only.
 */
class LanguageController extends Controller
{
    /**
     * Languages available for publishing. Pass `?all=1` (admin UI) to include
     * deactivated ones, which still label existing courses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Language::ordered();

        if (!$request->boolean('all')) {
            $query->active();
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}(-[a-z]{2})?$/i', Rule::unique('languages', 'code')],
            'name' => 'required|string|max:255',
            'native_name' => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
        ]);

        $validated['code'] = strtolower($validated['code']);

        $language = Language::create($validated + [
            'is_active' => true,
            'sort_order' => (Language::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json(['data' => $language], 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $language = Language::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'native_name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
        ]);

        $language->update($validated);

        return response()->json(['data' => $language->fresh()]);
    }

    /**
     * Deactivate rather than delete: courses keep pointing at their language
     * code, and an upload for an existing course must keep validating.
     */
    public function destroy(string $code): JsonResponse
    {
        $language = Language::where('code', $code)->firstOrFail();
        $language->update(['is_active' => false]);

        return response()->json(['data' => $language->fresh()]);
    }
}
