<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseSkillConfig;
use App\Models\UserEloProfile;
use App\Services\SkillComputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSkillController extends Controller
{
    /**
     * Compute and return skill vector for a student.
     *
     * If course_id is provided, uses that course's formula.
     * Otherwise falls back to default GPF domain grouping.
     *
     * GET /api/students/{id}/skill-vector?course_id=X
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $profile = UserEloProfile::forUser($id)->first();

        if (!$profile || !$profile->profil_elo) {
            return response()->json([
                'data' => [
                    'student_id' => $id,
                    'skills' => [],
                    'message' => 'Žádný ELO profil',
                ],
            ]);
        }

        $profilElo = $profile->profil_elo;
        $profilPocet = $profile->profil_pocet ?? array_fill(0, count($profilElo), 0);

        // Load config from course if specified
        $formulaJson = SkillComputationService::defaultGpfFormula();
        $confidenceC = 2.5;
        $minCount = 10;
        $scaleMin = 1.0;
        $scaleMax = 10.0;
        $configSource = 'default_gpf';

        if ($request->filled('course_id')) {
            $config = CourseSkillConfig::where('course_id', $request->input('course_id'))->first();

            if ($config) {
                $formulaJson = $config->formula_json;
                $confidenceC = $config->confidence_c;
                $minCount = $config->min_count;
                $scaleMin = $config->display_scale_min;
                $scaleMax = $config->display_scale_max;
                $configSource = 'course_config';
            }
        }

        $skills = SkillComputationService::computeSkills(
            profilElo: $profilElo,
            profilPocet: $profilPocet,
            formulaJson: $formulaJson,
            confidenceC: $confidenceC,
            minCount: $minCount,
            scaleMin: $scaleMin,
            scaleMax: $scaleMax,
        );

        return response()->json([
            'data' => [
                'student_id' => $id,
                'config_source' => $configSource,
                'skills' => $skills,
                'profile_updated_at' => $profile->updated_at->toIso8601String(),
            ],
        ]);
    }
}
