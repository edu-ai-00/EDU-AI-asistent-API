<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkillVector;
use App\Models\VectorDimension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VectorController extends Controller
{
    /**
     * List all vectors.
     *
     * GET /api/vectors
     */
    public function index(): JsonResponse
    {
        $vectors = SkillVector::withCount('dimensions')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $vectors->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'description' => $v->description,
                'dimension_count' => $v->dimension_count,
                'dimensions_count' => $v->dimensions_count,
                'version' => $v->version,
                'is_public' => $v->is_public,
                'created_at' => $v->created_at->toIso8601String(),
                'updated_at' => $v->updated_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create a new vector.
     *
     * POST /api/vectors
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'dimension_count' => 'required|integer|min:1|max:500',
            'is_public' => 'nullable|boolean',
        ]);

        $vector = SkillVector::create($validated);

        return response()->json([
            'data' => $vector,
            'message' => 'Vector created',
        ], 201);
    }

    /**
     * Show a vector with its dimensions.
     *
     * GET /api/vectors/{id}
     */
    public function show(string $id): JsonResponse
    {
        $vector = SkillVector::with('dimensions')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $vector->id,
                'name' => $vector->name,
                'description' => $vector->description,
                'dimension_count' => $vector->dimension_count,
                'version' => $vector->version,
                'is_public' => $vector->is_public,
                'dimensions' => $vector->dimensions->map(fn ($d) => [
                    'id' => $d->id,
                    'dimension_index' => $d->dimension_index,
                    'code' => $d->code,
                    'name' => $d->name,
                    'domain_code' => $d->domain_code,
                    'domain_name' => $d->domain_name,
                    'construct_name' => $d->construct_name,
                    'description' => $d->description,
                    'tags' => $d->tags,
                ]),
                'created_at' => $vector->created_at->toIso8601String(),
                'updated_at' => $vector->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update a vector.
     *
     * PUT /api/vectors/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'dimension_count' => 'sometimes|integer|min:1|max:500',
            'is_public' => 'nullable|boolean',
        ]);

        $vector->update($validated);

        return response()->json([
            'data' => $vector->fresh(),
            'message' => 'Vector updated',
        ]);
    }

    /**
     * Delete a vector.
     *
     * DELETE /api/vectors/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);
        $vector->delete();

        return response()->json(['message' => 'Vector deleted']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Dimensions
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * List dimensions for a vector.
     *
     * GET /api/vectors/{id}/dimensions
     */
    public function dimensionsIndex(string $id): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);
        $dimensions = $vector->dimensions;

        return response()->json([
            'data' => $dimensions->map(fn ($d) => [
                'id' => $d->id,
                'dimension_index' => $d->dimension_index,
                'code' => $d->code,
                'name' => $d->name,
                'domain_code' => $d->domain_code,
                'domain_name' => $d->domain_name,
                'construct_name' => $d->construct_name,
                'description' => $d->description,
                'tags' => $d->tags,
            ]),
        ]);
    }

    /**
     * Add a single dimension to a vector.
     *
     * POST /api/admin/vectors/{id}/dimensions
     */
    public function dimensionsStore(Request $request, string $id): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);

        $validated = $request->validate([
            'dimension_index' => 'required|integer|min:0',
            'code' => 'nullable|string|max:20',
            'name' => 'required|string|max:255',
            'domain_code' => 'nullable|string|max:10',
            'domain_name' => 'nullable|string|max:255',
            'construct_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $validated['vector_id'] = $vector->id;
        $dimension = VectorDimension::create($validated);

        // Update dimension_count
        $vector->update(['dimension_count' => $vector->dimensions()->count()]);

        return response()->json([
            'data' => $dimension,
            'message' => 'Dimension created',
        ], 201);
    }

    /**
     * Bulk import dimensions for a vector.
     * Replaces all existing dimensions with the provided set.
     *
     * POST /api/admin/vectors/{id}/dimensions/bulk
     */
    public function dimensionsBulkStore(Request $request, string $id): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);

        $validated = $request->validate([
            'dimensions' => 'required|array|min:1',
            'dimensions.*.dimension_index' => 'required|integer|min:0',
            'dimensions.*.code' => 'nullable|string|max:20',
            'dimensions.*.name' => 'required|string|max:255',
            'dimensions.*.domain_code' => 'nullable|string|max:10',
            'dimensions.*.domain_name' => 'nullable|string|max:255',
            'dimensions.*.construct_name' => 'nullable|string|max:255',
            'dimensions.*.description' => 'nullable|string',
            'dimensions.*.tags' => 'nullable|array',
        ]);

        // Replace all dimensions
        $vector->dimensions()->delete();

        $created = [];
        foreach ($validated['dimensions'] as $dimData) {
            $dimData['vector_id'] = $vector->id;
            $created[] = VectorDimension::create($dimData);
        }

        // Update dimension_count to match
        $vector->update(['dimension_count' => count($created)]);

        return response()->json([
            'data' => collect($created)->map(fn ($d) => [
                'id' => $d->id,
                'dimension_index' => $d->dimension_index,
                'code' => $d->code,
                'name' => $d->name,
                'domain_code' => $d->domain_code,
                'domain_name' => $d->domain_name,
            ]),
            'message' => count($created) . ' dimensions imported',
        ], 201);
    }

    /**
     * Update a single dimension.
     *
     * PUT /api/admin/vectors/{id}/dimensions/{dimId}
     */
    public function dimensionsUpdate(Request $request, string $id, string $dimId): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);
        $dimension = VectorDimension::where('vector_id', $vector->id)->findOrFail($dimId);

        $validated = $request->validate([
            'dimension_index' => 'sometimes|integer|min:0',
            'code' => 'nullable|string|max:20',
            'name' => 'sometimes|string|max:255',
            'domain_code' => 'nullable|string|max:10',
            'domain_name' => 'nullable|string|max:255',
            'construct_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $dimension->update($validated);

        return response()->json([
            'data' => $dimension->fresh(),
            'message' => 'Dimension updated',
        ]);
    }

    /**
     * Delete a single dimension.
     *
     * DELETE /api/admin/vectors/{id}/dimensions/{dimId}
     */
    public function dimensionsDestroy(string $id, string $dimId): JsonResponse
    {
        $vector = SkillVector::findOrFail($id);
        $dimension = VectorDimension::where('vector_id', $vector->id)->findOrFail($dimId);

        $dimension->delete();

        // Update dimension_count
        $vector->update(['dimension_count' => $vector->dimensions()->count()]);

        return response()->json(['message' => 'Dimension deleted']);
    }
}
