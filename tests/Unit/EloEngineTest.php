<?php

namespace Tests\Unit;

use App\Services\EloEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pilot 2 ELO engine (BR-P2UAT2).
 *
 * The numeric fixtures are mirrored in the app's engine test
 * (eduai-app/test/elo_engine_test.dart): the backfill must reproduce exactly
 * what the app computes live.
 */
class EloEngineTest extends TestCase
{
    private const K = 3;

    /**
     * Run one interaction on a single active dimension.
     *
     * @return array{profil_elo: array, profil_pocet: array, elo_vector: array, item_pocet: array, updated_indices: array}
     */
    private function runOne(
        ?float $r,
        float $d,
        float $score,
        int $n = 0,
        int $m = 0,
        float $relation = 2.0,
        bool $skip = false
    ): array {
        $profil = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $profilPocet = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
        $rel = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);
        $elo = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $itemPocet = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);

        $profil[self::K] = $r;
        $profilPocet[self::K] = $n;
        $rel[self::K] = $relation;
        $elo[self::K] = $d;
        $itemPocet[self::K] = $m;

        return EloEngine::updateTask($profil, $profilPocet, $rel, $elo, $itemPocet, $score, $skip);
    }

    public function test_expected_scores_at_match(): void
    {
        $this->assertEqualsWithDelta(0.5, EloEngine::expectedForStudent(6.0, 6.0), 1e-12);
        $this->assertEqualsWithDelta(0.75, EloEngine::expectedForTask(6.0, 6.0), 1e-12);
        $this->assertEqualsWithDelta(log(3), EloEngine::taskBias(), 1e-12);
    }

    public function test_k_factors(): void
    {
        $this->assertEqualsWithDelta(1.0, EloEngine::studentK(0), 1e-12);
        $this->assertEqualsWithDelta(0.3 + 0.7 / 1.3, EloEngine::studentK(3), 1e-12);
        $this->assertEqualsWithDelta(0.3, EloEngine::studentK(1000000), 1e-5);

        $this->assertEqualsWithDelta(1.0, EloEngine::taskK(0), 1e-12);
        $this->assertEqualsWithDelta(1 / 1.5, EloEngine::taskK(5), 1e-12);
        $this->assertEqualsWithDelta(0.0, EloEngine::taskK(1000000), 1e-5);
    }

    public function test_matched_ratings_first_success(): void
    {
        $res = $this->runOne(6.0, 6.0, 1.0);

        $this->assertEqualsWithDelta(6.5, $res['profil_elo'][self::K], 1e-9);
        $this->assertEqualsWithDelta(5.75, $res['elo_vector'][self::K], 1e-9);
        $this->assertSame(1, $res['profil_pocet'][self::K]);
        $this->assertSame(1, $res['item_pocet'][self::K]);
        $this->assertSame([self::K], $res['updated_indices']);
    }

    public function test_weaker_student_fails_harder_task(): void
    {
        $res = $this->runOne(5.0, 7.0, 0.0, 3, 5);

        $this->assertEqualsWithDelta(4.774502962, $res['profil_elo'][self::K], 1e-9);
        $this->assertEqualsWithDelta(7.349755409, $res['elo_vector'][self::K], 1e-9);
        $this->assertSame(4, $res['profil_pocet'][self::K]);
        $this->assertSame(6, $res['item_pocet'][self::K]);
    }

    public function test_strong_student_half_scores_easy_task(): void
    {
        $res = $this->runOne(8.0, 4.0, 0.5, 20, 50);

        $this->assertEqualsWithDelta(7.796908225, $res['profil_elo'][self::K], 1e-9);
        $this->assertEqualsWithDelta(4.076139245, $res['elo_vector'][self::K], 1e-9);
    }

    public function test_only_relation_two_is_active(): void
    {
        foreach ([0.0, 1.0] as $relation) {
            $res = $this->runOne(6.0, 6.0, 1.0, 0, 0, $relation);
            $this->assertSame([], $res['updated_indices']);
            $this->assertSame(6.0, $res['profil_elo'][self::K]);
        }
    }

    public function test_integer_relation_from_stored_json_still_matches(): void
    {
        $res = $this->runOne(6.0, 6.0, 1.0, 0, 0, 2);
        $this->assertSame([self::K], $res['updated_indices']);
    }

    public function test_first_contact_seeds_only(): void
    {
        $win = $this->runOne(null, 7.5, 0.5);
        $this->assertSame(7.5, $win['profil_elo'][self::K]);
        $this->assertSame(0, $win['profil_pocet'][self::K]);
        $this->assertSame(7.5, $win['elo_vector'][self::K]);
        $this->assertSame(0, $win['item_pocet'][self::K]);
        $this->assertSame([self::K], $win['updated_indices']);

        $lose = $this->runOne(null, 7.5, 0.25);
        $this->assertSame(EloEngine::DEFAULT_STUDENT_RATING, $lose['profil_elo'][self::K]);
        $this->assertSame(0, $lose['profil_pocet'][self::K]);
    }

    public function test_skip_elo_update_changes_nothing(): void
    {
        $res = $this->runOne(6.0, 6.0, 1.0, 2, 4, 2.0, true);

        $this->assertSame(6.0, $res['profil_elo'][self::K]);
        $this->assertSame(2, $res['profil_pocet'][self::K]);
        $this->assertSame(6.0, $res['elo_vector'][self::K]);
        $this->assertSame(4, $res['item_pocet'][self::K]);
        $this->assertSame([], $res['updated_indices']);
    }

    public function test_null_task_rating_is_skipped(): void
    {
        $profil = array_fill(0, EloEngine::GPF_DIMENSIONS, 6.0);
        $rel = array_fill(0, EloEngine::GPF_DIMENSIONS, 2);
        $elo = array_fill(0, EloEngine::GPF_DIMENSIONS, null);
        $zeros = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);

        $res = EloEngine::updateTask($profil, $zeros, $rel, $elo, $zeros, 1.0);

        $this->assertSame([], $res['updated_indices']);
    }

    public function test_short_vectors_walk_the_overlap(): void
    {
        $profil = array_fill(0, EloEngine::GPF_DIMENSIONS, 6.0);
        $zeros = array_fill(0, EloEngine::GPF_DIMENSIONS, 0);

        $res = EloEngine::updateTask($profil, $zeros, [2, 0], [6.0], $zeros, 1.0);

        $this->assertSame([0], $res['updated_indices']);
        $this->assertEqualsWithDelta(6.5, $res['profil_elo'][0], 1e-9);
    }
}
