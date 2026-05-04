<?php

namespace App\Services;

/**
 * Computes displayable skill values from ELO profile data + formula configuration.
 *
 * Port of the Python reference script's compute_domain_summary() logic,
 * generalized to support arbitrary formula_json groupings.
 */
class SkillComputationService
{
    /**
     * Compute skills for a student using course skill config formula.
     *
     * @param  array       $profilElo    35-element student ELO ratings (nullable elements)
     * @param  array       $profilPocet  35-element task counts
     * @param  array       $formulaJson  Skill definitions: {"SkillName": {"dims": [0,1,2], "weights": [1,1,0.8]}}
     * @param  float       $confidenceC  Spread parameter for confidence interval
     * @param  int         $minCount     Minimum task count to include a dimension
     * @param  float       $scaleMin     Display scale minimum
     * @param  float       $scaleMax     Display scale maximum
     * @return array<int, array>         Array of computed skill objects
     */
    public static function computeSkills(
        array $profilElo,
        array $profilPocet,
        array $formulaJson,
        float $confidenceC = 2.5,
        int $minCount = 10,
        float $scaleMin = 1.0,
        float $scaleMax = 10.0,
    ): array {
        $skills = [];

        foreach ($formulaJson as $skillName => $config) {
            $dims = $config['dims'] ?? [];
            $weights = $config['weights'] ?? array_fill(0, count($dims), 1.0);

            $skills[] = self::computeSingleSkill(
                name: $skillName,
                dimensionIndices: $dims,
                weights: $weights,
                profilElo: $profilElo,
                profilPocet: $profilPocet,
                confidenceC: $confidenceC,
                minCount: $minCount,
                scaleMin: $scaleMin,
                scaleMax: $scaleMax,
            );
        }

        return $skills;
    }

    /**
     * Compute a single skill (weighted average of selected dimensions).
     */
    public static function computeSingleSkill(
        string $name,
        array $dimensionIndices,
        array $weights,
        array $profilElo,
        array $profilPocet,
        float $confidenceC,
        int $minCount,
        float $scaleMin,
        float $scaleMax,
    ): array {
        $eligibleElo = [];
        $eligibleWeights = [];
        $eligibleCounts = [];
        $excludedCount = 0;

        foreach ($dimensionIndices as $i => $dimIdx) {
            $elo = $profilElo[$dimIdx] ?? null;
            $count = $profilPocet[$dimIdx] ?? 0;
            $weight = $weights[$i] ?? 1.0;

            if ($elo === null || $count < $minCount) {
                $excludedCount++;
                continue;
            }

            $eligibleElo[] = $elo;
            $eligibleWeights[] = $weight;
            $eligibleCounts[] = $count;
        }

        $includedCount = count($eligibleElo);

        if ($includedCount === 0) {
            return [
                'name' => $name,
                'level' => null,
                'interval_low' => null,
                'interval_high' => null,
                'confidence_label' => null,
                'included_count' => 0,
                'excluded_count' => $excludedCount,
                'median_count' => null,
                'message' => 'Nedostatek dat',
            ];
        }

        // Weighted average
        $totalWeight = array_sum($eligibleWeights);
        $weightedSum = 0.0;
        for ($i = 0; $i < $includedCount; $i++) {
            $weightedSum += $eligibleElo[$i] * $eligibleWeights[$i];
        }
        $meanElo = $weightedSum / $totalWeight;

        // Median of task counts for confidence
        $sortedCounts = $eligibleCounts;
        sort($sortedCounts);
        $mid = intdiv(count($sortedCounts), 2);
        $medianCount = count($sortedCounts) % 2 === 0
            ? ($sortedCounts[$mid - 1] + $sortedCounts[$mid]) / 2.0
            : (float) $sortedCounts[$mid];

        // Confidence interval: c / sqrt(median_count)
        $halfWidth = $medianCount > 0 ? $confidenceC / sqrt($medianCount) : $confidenceC;
        $intervalLow = max($scaleMin, $meanElo - $halfWidth);
        $intervalHigh = min($scaleMax, $meanElo + $halfWidth);

        $confidenceLabel = self::confidenceLabel($medianCount);

        return [
            'name' => $name,
            'level' => round($meanElo, 2),
            'interval_low' => round($intervalLow, 2),
            'interval_high' => round($intervalHigh, 2),
            'confidence_label' => $confidenceLabel,
            'included_count' => $includedCount,
            'excluded_count' => $excludedCount,
            'median_count' => $medianCount,
            'message' => null,
        ];
    }

    /**
     * Verbal confidence label based on median task count.
     * Matches Python reference: _confidence_label_from_median_count().
     */
    public static function confidenceLabel(?float $medianCount): ?string
    {
        if ($medianCount === null || $medianCount < 10) {
            return null;
        }
        if ($medianCount < 20) {
            return 'nižší';
        }
        if ($medianCount < 30) {
            return 'střední';
        }

        return 'vyšší';
    }

    /**
     * Build the default GPF 5-domain formula for courses without a custom config.
     *
     * @return array Formula JSON matching the GPF domain grouping
     */
    public static function defaultGpfFormula(): array
    {
        return [
            'Číslo a operace' => [
                'dims' => range(0, 16),
                'weights' => array_fill(0, 17, 1.0),
            ],
            'Míry' => [
                'dims' => range(17, 21),
                'weights' => array_fill(0, 5, 1.0),
            ],
            'Geometrie' => [
                'dims' => range(22, 24),
                'weights' => array_fill(0, 3, 1.0),
            ],
            'Statistika a pravděpodobnost' => [
                'dims' => range(25, 28),
                'weights' => array_fill(0, 4, 1.0),
            ],
            'Algebra' => [
                'dims' => range(29, 34),
                'weights' => array_fill(0, 6, 1.0),
            ],
        ];
    }
}
