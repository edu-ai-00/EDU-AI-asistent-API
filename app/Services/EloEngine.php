<?php

namespace App\Services;

/**
 * Bidirectional ELO engine for adaptive learning.
 *
 * Port of the Dart EloEngine (lib/core/elo/elo_engine.dart).
 * Adjusts both the student's proficiency rating and the task's difficulty
 * rating after each interaction. Uses 35 GPF subconstructs (N1.1–A3.4).
 *
 * Pure PHP — no framework dependencies.
 */
class EloEngine
{
    /** Number of GPF subconstructs. */
    const GPF_DIMENSIONS = 35;

    /** Default student rating used when no prior data exists. */
    const DEFAULT_STUDENT_RATING = 6.0;

    /** Sigmoid steepness. */
    const LAMBDA = 0.5;

    /** Base K-factor for student updates. */
    const K0 = 1.0;

    /** Student K-factor decay rate. */
    const ALPHA = 0.1;

    /** Base K-factor for task (item) updates. */
    const K0_ITEM = 0.01;

    /** Task K-factor decay rate. */
    const ALPHA_ITEM = 0.01;

    /** Bias added to student rating when computing expected score for the task. */
    const BIAS_FOR_ITEM = 1.0;

    /**
     * Standard sigmoid function.
     */
    public static function sigmoid(float $x): float
    {
        return 1.0 / (1.0 + exp(-$x));
    }

    /**
     * Perform a bidirectional ELO update for a single task interaction.
     *
     * @param  array<int, float|null>  $profilElo       35-element student ratings
     * @param  array<int, int>         $profilPocet     35-element task counts
     * @param  array<int, int|float>   $relationVector  block's relation_vector (0/1/2)
     * @param  array<int, float|null>  $eloVector       block's elo_vector
     * @param  array<int, int>         $itemPocet       block's global solve counts
     * @param  float                   $score           0.0–1.0
     * @return array{profil_elo: array, profil_pocet: array, elo_vector: array, item_pocet: array, updated_indices: array}
     */
    public static function updateTask(
        array $profilElo,
        array $profilPocet,
        array $relationVector,
        array $eloVector,
        array $itemPocet,
        float $score
    ): array {
        // Work on copies so callers keep their originals.
        $newProfilElo = $profilElo;
        $newProfilPocet = $profilPocet;
        $newEloVector = $eloVector;
        $newItemPocet = $itemPocet;
        $updatedIndices = [];

        $length = min(self::GPF_DIMENSIONS, count($relationVector));

        for ($k = 0; $k < $length; $k++) {
            // Only process subconstructs with strong relation AND valid task ELO.
            if ($relationVector[$k] <= 1) {
                continue;
            }
            if (!isset($eloVector[$k]) || $eloVector[$k] === null || $eloVector[$k] <= 0) {
                continue;
            }

            $d = (float) $eloVector[$k]; // task difficulty
            $r = $newProfilElo[$k] ?? null; // student rating (may be null)

            // ── First-contact handling ──
            if ($r === null) {
                $expectedFirst = self::sigmoid(self::LAMBDA * (self::DEFAULT_STUDENT_RATING - $d));
                if ($score >= $expectedFirst) {
                    $r = $d; // student matches task
                } else {
                    $r = self::DEFAULT_STUDENT_RATING;
                }
            }

            // ── Student update ──
            $delta = $r - $d;
            $expected = self::sigmoid(self::LAMBDA * $delta);
            $kStudent = self::K0 / (1 + self::ALPHA * $newProfilPocet[$k]);
            $studentDiff = $kStudent * ($score - $expected);
            $newR = max(0.0, min(10.0, $r + $studentDiff));

            // ── Task update (with bias) ──
            $biasedDelta = ($r + self::BIAS_FOR_ITEM) - $d;
            $expectedBiased = self::sigmoid(self::LAMBDA * $biasedDelta);
            $kTask = self::K0_ITEM / (1 + self::ALPHA_ITEM * $newItemPocet[$k]);
            $taskDiff = -$kTask * ($score - $expectedBiased);
            $newD = max(0.0, min(10.0, $d + $taskDiff));

            // ── Apply ──
            $newProfilElo[$k] = $newR;
            $newProfilPocet[$k] += 1;
            $newEloVector[$k] = $newD;
            $newItemPocet[$k] += 1;

            $updatedIndices[] = $k;
        }

        return [
            'profil_elo' => $newProfilElo,
            'profil_pocet' => $newProfilPocet,
            'elo_vector' => $newEloVector,
            'item_pocet' => $newItemPocet,
            'updated_indices' => $updatedIndices,
        ];
    }
}
