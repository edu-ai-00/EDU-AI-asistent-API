<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSkillConfig;
use App\Models\EloInteraction;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Services\SkillComputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseSkillsController extends Controller
{
    /**
     * Teacher overview: matrix of students × skills for a course.
     *
     * GET /api/courses/{id}/skills/overview
     */
    public function overview(int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        // Get config (or default)
        $config = CourseSkillConfig::where('course_id', $course->id)->first();
        $formulaJson = $config?->formula_json ?? SkillComputationService::defaultGpfFormula();
        $confidenceC = $config?->confidence_c ?? 2.5;
        $minCount = $config?->min_count ?? 10;
        $scaleMin = $config?->display_scale_min ?? 1.0;
        $scaleMax = $config?->display_scale_max ?? 10.0;

        // Get all enrolled students
        $enrollments = UserCourse::where('course_id', $course->id)
            ->with('user:id,name,email,classroom_id')
            ->get();

        $studentIds = $enrollments->pluck('user_id')->all();

        // Load ELO profiles for all enrolled students
        $profiles = UserEloProfile::whereIn('user_id', $studentIds)
            ->get()
            ->keyBy('user_id');

        // Skill names for the header
        $skillNames = array_keys($formulaJson);

        // Compute per-student
        $students = [];
        foreach ($enrollments as $enrollment) {
            $profile = $profiles->get($enrollment->user_id);
            $profilElo = $profile?->profil_elo;
            $profilPocet = $profile?->profil_pocet;

            if (!$profilElo) {
                $students[] = [
                    'user' => $enrollment->user ? [
                        'id' => $enrollment->user->id,
                        'name' => $enrollment->user->name,
                        'classroom_id' => $enrollment->user->classroom_id,
                    ] : null,
                    'skills' => array_fill(0, count($skillNames), null),
                    'has_data' => false,
                ];
                continue;
            }

            $skills = SkillComputationService::computeSkills(
                profilElo: $profilElo,
                profilPocet: $profilPocet ?? array_fill(0, count($profilElo), 0),
                formulaJson: $formulaJson,
                confidenceC: $confidenceC,
                minCount: $minCount,
                scaleMin: $scaleMin,
                scaleMax: $scaleMax,
            );

            $students[] = [
                'user' => $enrollment->user ? [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'classroom_id' => $enrollment->user->classroom_id,
                ] : null,
                'skills' => $skills,
                'has_data' => true,
            ];
        }

        return response()->json([
            'data' => [
                'course_id' => $course->id,
                'skill_names' => $skillNames,
                'students' => $students,
            ],
            'meta' => [
                'total_students' => count($students),
                'students_with_data' => collect($students)->where('has_data', true)->count(),
            ],
        ]);
    }

    /**
     * Aggregate statistics for a course.
     *
     * GET /api/courses/{id}/skills/stats
     */
    public function stats(int $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        // Use the string course_id for interaction queries
        $courseIdStr = $course->course_id;

        $interactions = EloInteraction::where('course_id', $courseIdStr);

        $totalTasks = $interactions->count();
        $uniqueUsers = $interactions->distinct('user_id')->count('user_id');

        // Score distribution
        $allInteractions = EloInteraction::where('course_id', $courseIdStr)->get();
        $correctCount = $allInteractions->where('score', '>=', 0.75)->count();
        $incorrectCount = $allInteractions->where('score', '<=', 0.0)->count();
        $partialCount = $totalTasks - $correctCount - $incorrectCount;

        $avgScore = $totalTasks > 0 ? round($allInteractions->avg('score'), 3) : null;

        // Per-skill averages (class-wide)
        $config = CourseSkillConfig::where('course_id', $course->id)->first();
        $formulaJson = $config?->formula_json ?? SkillComputationService::defaultGpfFormula();
        $confidenceC = $config?->confidence_c ?? 2.5;
        $minCount = $config?->min_count ?? 10;

        $enrollments = UserCourse::where('course_id', $course->id)->pluck('user_id');
        $profiles = UserEloProfile::whereIn('user_id', $enrollments)->get();

        // Compute class average per skill
        $skillAverages = [];
        $skillNames = array_keys($formulaJson);

        foreach ($skillNames as $skillIdx => $skillName) {
            $levels = [];

            foreach ($profiles as $profile) {
                if (!$profile->profil_elo) continue;

                $skills = SkillComputationService::computeSkills(
                    profilElo: $profile->profil_elo,
                    profilPocet: $profile->profil_pocet ?? array_fill(0, 35, 0),
                    formulaJson: $formulaJson,
                    confidenceC: $confidenceC,
                    minCount: $minCount,
                );

                if (isset($skills[$skillIdx]) && $skills[$skillIdx]['level'] !== null) {
                    $levels[] = $skills[$skillIdx]['level'];
                }
            }

            $skillAverages[] = [
                'name' => $skillName,
                'class_avg' => count($levels) > 0 ? round(array_sum($levels) / count($levels), 2) : null,
                'students_with_data' => count($levels),
            ];
        }

        return response()->json([
            'data' => [
                'course_id' => $course->id,
                'total_tasks' => $totalTasks,
                'unique_users' => $uniqueUsers,
                'avg_score' => $avgScore,
                'correct_count' => $correctCount,
                'incorrect_count' => $incorrectCount,
                'partial_count' => $partialCount,
                'correct_percent' => $totalTasks > 0 ? round($correctCount / $totalTasks * 100, 1) : null,
                'skill_averages' => $skillAverages,
            ],
        ]);
    }
}
