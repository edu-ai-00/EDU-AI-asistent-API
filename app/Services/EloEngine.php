<?php

namespace App\Services;

/**
 * Asymmetric bidirectional ELO engine for adaptive learning (Pilot 2).
 *
 * Port of the Dart EloEngine (eduai-app/lib/core/elo/elo_engine.dart). The
 * admin ELO backfill replays interactions server-side with this class while
 * the app updates live with the Dart one, so the two must land on the same
 * numbers — change them together. The shared numeric fixtures live in
 * tests/Unit/EloEngineTest.php and eduai-app/test/elo_engine_test.dart.
 *
 * Pilot 2 rules:
 * - 35 GPF subconstructs; relation_vector is discrete 0/1/2 and only
 *   relation == 2 is updated.
 * - Student and task use different expected-score curves:
 *     student: E_L = 0.50 when L == D;   task: E_D = 0.75 when L == D.
 *   Each bias is derived from its target (logit), never stored separately.
 * - Student K decays to a non-zero asymptote; task K decays to zero.
 * - Gaming/invalid attempts must not update ratings or counters — the caller
 *   passes $skipEloUpdate = true.
 * - First contact (student unrated in an active dimension) only seeds the
 *   student rating; no regular update, no task update, no counter increment.
 *
 * Pure PHP — no framework dependencies.
 */
class EloEngine
{
    /** Number of GPF subconstructs. */
    const GPF_DIMENSIONS = 35;

    /** Default student rating used when no prior data exists. */
    const DEFAULT_STUDENT_RATING = 6.0;

    /** Version label of the Pilot 2 configuration below. */
    const CONFIG_VERSION = 'pilot2-elo-v1';

    /** Sigmoid steepness. */
    const LAMBDA = 0.5;

    /** Expected student score when student rating equals task difficulty. */
    const TARGET_STUDENT_AT_MATCH = 0.50;

    /** Expected score used to calibrate task difficulty when L == D. */
    const TARGET_TASK_AT_MATCH = 0.75;

    /** Initial student K-factor. */
    const K0_STUDENT = 1.0;

    /** Long-run lower asymptote of the student K-factor. */
    const K_MIN_STUDENT = 0.3;

    /** Student K-factor decay rate. */
    const ALPHA_STUDENT = 0.1;

    /** Initial task K-factor. */
    const K0_TASK = 1.0;

    /** Task K-factor decay rate. */
    const ALPHA_TASK = 0.1;

    /**
     * Standard sigmoid function.
     */
    public static function sigmoid(float $x): float
    {
        return 1.0 / (1.0 + exp(-$x));
    }

    /**
     * Bias of the student curve, derived from its target.
     */
    public static function studentBias(): float
    {
        return self::logit(self::TARGET_STUDENT_AT_MATCH);
    }

    /**
     * Bias of the task-calibration curve, derived from its target.
     */
    public static function taskBias(): float
    {
        return self::logit(self::TARGET_TASK_AT_MATCH);
    }

    /**
     * Student expected score: E_L = sigmoid(lambda * (L - D) + b_L).
     */
    public static function expectedForStudent(float $studentRating, float $taskDifficulty): float
    {
        return self::sigmoid(self::LAMBDA * ($studentRating - $taskDifficulty) + self::studentBias());
    }

    /**
     * Task-calibration expected score: E_D = sigmoid(lambda * (L - D) + b_D).
     */
    public static function expectedForTask(float $studentRating, float $taskDifficulty): float
    {
        return self::sigmoid(self::LAMBDA * ($studentRating - $taskDifficulty) + self::taskBias());
    }

    /**
     * Student K-factor with a non-zero lower asymptote:
     * K_L(n) = K_min + (K0 - K_min) / (1 + alpha * n).
     */
    public static function studentK(int $previousUpdates): float
    {
        return self::K_MIN_STUDENT
            + (self::K0_STUDENT - self::K_MIN_STUDENT) / (1.0 + self::ALPHA_STUDENT * max(0, $previousUpdates));
    }

    /**
     * Task K-factor using the plain reciprocal form: K_D(m) = K0 / (1 + alpha * m).
     * Unlike the student K-factor, this converges to zero.
     */
    public static function taskK(int $previousUpdates): float
    {
        return self::K0_TASK / (1.0 + self::ALPHA_TASK * max(0, $previousUpdates));
    }

    /**
     * Perform a bidirectional ELO update for a single task interaction.
     *
     * Missing or malformed slots are skipped rather than rejected — backfill
     * input comes straight from stored course JSON and older profiles.
     *
     * @param  array<int, float|null>  $profilElo       35-element student ratings
     * @param  array<int, int>         $profilPocet     35-element student update counts
     * @param  array<int, int|float>   $relationVector  block's relation_vector (0/1/2)
     * @param  array<int, float|null>  $eloVector       block's elo_vector
     * @param  array<int, int>         $itemPocet       block's global update counts
     * @param  float                   $score           0.0–1.0
     * @param  bool                    $skipEloUpdate   true for gaming / invalid attempts
     * @return array{profil_elo: array, profil_pocet: array, elo_vector: array, item_pocet: array, updated_indices: array}
     */
    public static function updateTask(
        array $profilElo,
        array $profilPocet,
        array $relationVector,
        array $eloVector,
        array $itemPocet,
        float $score,
        bool $skipEloUpdate = false
    ): array {
        // Work on copies so callers keep their originals.
        $newProfilElo = $profilElo;
        $newProfilPocet = $profilPocet;
        $newEloVector = $eloVector;
        $newItemPocet = $itemPocet;
        $updatedIndices = [];

        if ($skipEloUpdate) {
            return [
                'profil_elo' => $newProfilElo,
                'profil_pocet' => $newProfilPocet,
                'elo_vector' => $newEloVector,
                'item_pocet' => $newItemPocet,
                'updated_indices' => $updatedIndices,
            ];
        }

        $length = min(self::GPF_DIMENSIONS, count($relationVector));

        for ($k = 0; $k < $length; $k++) {
            // Relation is deliberately unweighted: only the main relation (2)
            // triggers an update.
            if (! isset($relationVector[$k]) || (float) $relationVector[$k] !== 2.0) {
                continue;
            }
            // A null task ELO in an active dimension cannot be updated.
            if (! isset($eloVector[$k]) || $eloVector[$k] === null) {
                continue;
            }

            $d = (float) $eloVector[$k]; // task difficulty
            $r = $newProfilElo[$k] ?? null; // student rating (may be null)

            // First-contact initialization: seed only, mirroring the app.
            if ($r === null) {
                $newProfilElo[$k] = $score >= self::TARGET_STUDENT_AT_MATCH
                    ? $d
                    : self::DEFAULT_STUDENT_RATING;
                $updatedIndices[] = $k;
                continue;
            }

            $r = (float) $r;
            $n = (int) ($newProfilPocet[$k] ?? 0);
            $m = (int) ($newItemPocet[$k] ?? 0);

            // ── Student update ──
            $expectedStudent = self::expectedForStudent($r, $d);
            $studentDelta = self::studentK($n) * ($score - $expectedStudent);
            $newR = max(0.0, min(10.0, $r + $studentDelta));

            // ── Task update ──
            $expectedTask = self::expectedForTask($r, $d);
            $taskDelta = self::taskK($m) * ($expectedTask - $score);
            $newD = max(0.0, min(10.0, $d + $taskDelta));

            // ── Apply ──
            $newProfilElo[$k] = $newR;
            $newProfilPocet[$k] = $n + 1;
            $newEloVector[$k] = $newD;
            $newItemPocet[$k] = $m + 1;

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

    private static function logit(float $p): float
    {
        return log($p / (1.0 - $p));
    }
}
