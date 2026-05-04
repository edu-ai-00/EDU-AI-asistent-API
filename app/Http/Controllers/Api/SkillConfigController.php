<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSkillConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillConfigController extends Controller
{
    /**
     * Get skill configs for all courses the user is enrolled in.
     *
     * GET /api/user/skill-configs
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $courseIds = $user->courses()->pluck('course_id');

        $configs = CourseSkillConfig::with('vector.dimensions')
            ->whereIn('course_id', $courseIds)
            ->get();

        return response()->json([
            'data' => $configs->map(fn ($config) => [
                'id' => $config->id,
                'course_id' => $config->course_id,
                'vector_id' => $config->vector_id,
                'formula_json' => $config->formula_json,
                'display_scale_min' => $config->display_scale_min,
                'display_scale_max' => $config->display_scale_max,
                'confidence_c' => $config->confidence_c,
                'min_count' => $config->min_count,
                'version' => $config->version,
                'vector' => $config->vector ? [
                    'id' => $config->vector->id,
                    'name' => $config->vector->name,
                    'dimension_count' => $config->vector->dimension_count,
                ] : null,
            ]),
        ]);
    }

    /**
     * Get skill config for a course.
     * Returns vector + dimensions + formula.
     *
     * GET /api/courses/{id}/skill-config
     */
    public function show(int $id): JsonResponse
    {
        $course = Course::findOrFail($id);
        $config = CourseSkillConfig::with('vector.dimensions')
            ->where('course_id', $course->id)
            ->first();

        if (!$config) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id' => $config->id,
                'course_id' => $config->course_id,
                'vector_id' => $config->vector_id,
                'formula_json' => $config->formula_json,
                'display_scale_min' => $config->display_scale_min,
                'display_scale_max' => $config->display_scale_max,
                'confidence_c' => $config->confidence_c,
                'min_count' => $config->min_count,
                'version' => $config->version,
                'is_default' => false,
                'vector' => $config->vector ? [
                    'id' => $config->vector->id,
                    'name' => $config->vector->name,
                    'dimension_count' => $config->vector->dimension_count,
                    'dimensions' => $config->vector->dimensions->map(fn ($d) => [
                        'dimension_index' => $d->dimension_index,
                        'code' => $d->code,
                        'name' => $d->name,
                        'domain_code' => $d->domain_code,
                        'domain_name' => $d->domain_name,
                    ]),
                ] : null,
            ],
        ]);
    }

    /**
     * Create or update skill config for a course.
     *
     * PUT /api/courses/{id}/skill-config
     */
    public function upsert(Request $request, int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        $validated = $request->validate([
            'vector_id' => 'required|uuid|exists:skill_vectors,id',
            'formula_json' => 'required|array|min:1',
            'formula_json.*.dims' => 'required|array|min:1',
            'formula_json.*.dims.*' => 'required|integer|min:0',
            'formula_json.*.weights' => 'nullable|array',
            'formula_json.*.weights.*' => 'nullable|numeric|min:0',
            'display_scale_min' => 'nullable|numeric|min:0',
            'display_scale_max' => 'nullable|numeric|min:1',
            'confidence_c' => 'nullable|numeric|min:0.1|max:20',
            'min_count' => 'nullable|integer|min:1|max:1000',
        ]);

        $config = CourseSkillConfig::updateOrCreate(
            ['course_id' => $course->id],
            [
                'vector_id' => $validated['vector_id'],
                'formula_json' => $validated['formula_json'],
                'display_scale_min' => $validated['display_scale_min'] ?? 1.0,
                'display_scale_max' => $validated['display_scale_max'] ?? 10.0,
                'confidence_c' => $validated['confidence_c'] ?? 2.5,
                'min_count' => $validated['min_count'] ?? 10,
            ]
        );

        // Also update the course's vector_id shortcut
        $course->update(['vector_id' => $validated['vector_id']]);

        return response()->json([
            'data' => $config->fresh(),
            'message' => 'Skill config saved',
        ]);
    }
}
