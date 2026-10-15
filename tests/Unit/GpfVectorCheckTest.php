<?php

namespace Tests\Unit;

use App\Support\GpfVectorCheck;
use PHPUnit\Framework\TestCase;

/**
 * GPF vector rules for the app's ELO engine (BR-S9SVNM).
 *
 * Mirrors the admin's save-time check (eduai-admin/src/lib/gpf-vectors.test.mjs):
 * both must flag exactly what the app's engine would reject.
 */
class GpfVectorCheckTest extends TestCase
{
    private function relation(array $overrides = []): array
    {
        return array_replace(array_fill(0, 35, 0), $overrides);
    }

    private function elo(array $overrides = []): array
    {
        return array_replace(array_fill(0, 35, 5.0), $overrides);
    }

    private function block(?array $gpf): array
    {
        return ['block_id' => 'B1', 'gpf' => $gpf];
    }

    public function test_valid_vectors_have_no_issues(): void
    {
        $this->assertSame([], GpfVectorCheck::block($this->block([
            'relation_vector' => $this->relation([3 => 2, 4 => 1, 5 => 2.0]),
            'elo_vector' => $this->elo([0 => 0, 1 => 10, 2 => null]),
        ])));
    }

    public function test_display_only_blocks_are_not_errors(): void
    {
        $this->assertSame([], GpfVectorCheck::block($this->block(['domain' => 'Algebra'])));
        $this->assertSame([], GpfVectorCheck::block($this->block(['relation_vector' => [], 'elo_vector' => []])));
        $this->assertSame([], GpfVectorCheck::block($this->block(null)));
    }

    public function test_relation_other_than_exactly_0_1_2_is_reported_with_its_slot(): void
    {
        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => $this->relation([3 => 1.5, 7 => 3, 9 => -1, 11 => 'dva', 12 => null]),
            'elo_vector' => $this->elo(),
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame('relation_vector', $issues[0]['field']);
        foreach (['[3] = 1.5', '[7] = 3', '[9] = -1', '[11] = "dva"', '[12] = null'] as $slot) {
            $this->assertStringContainsString($slot, $issues[0]['message']);
        }
    }

    /** BR-6DRK92: the app and EloEngine read "2" as 2, so it is not broken. */
    public function test_numeric_strings_count_as_the_numbers_they_spell(): void
    {
        // A34-Funkce_05 as stored: every slot of both vectors is a string.
        $relation = array_map('strval', $this->relation([31 => 1, 34 => 2]));
        $elo = array_map('strval', array_replace(array_fill(0, 35, 0), [31 => 7, 34 => 8]));

        $this->assertSame([], GpfVectorCheck::block($this->block([
            'relation_vector' => $relation,
            'elo_vector' => $elo,
        ])));

        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => $this->relation([0 => '3', 1 => '']),
            'elo_vector' => $this->elo([0 => '10.5', 1 => '0x5']),
        ]));

        $this->assertSame(['relation_vector', 'elo_vector'], array_column($issues, 'field'));
        $this->assertStringContainsString('[0] = "3"', $issues[0]['message']);
        $this->assertStringContainsString('[1] = ""', $issues[0]['message']);
        $this->assertStringContainsString('[0] = "10.5"', $issues[1]['message']);
        $this->assertStringContainsString('[1] = "0x5"', $issues[1]['message']);
    }

    public function test_elo_outside_0_to_10_is_reported(): void
    {
        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => $this->relation(),
            'elo_vector' => $this->elo([0 => 10.5, 1 => -0.1]),
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame('elo_vector', $issues[0]['field']);
        $this->assertStringContainsString('[0] = 10.5', $issues[0]['message']);
        $this->assertStringContainsString('[1] = -0.1', $issues[0]['message']);
    }

    public function test_vector_not_exactly_35_slots_is_reported(): void
    {
        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => [0, 2, 1],
            'elo_vector' => array_fill(0, 40, 5),
        ]));

        $this->assertSame(['relation_vector', 'elo_vector'], array_column($issues, 'field'));
        $this->assertStringContainsString('has 3 slots', $issues[0]['message']);
        $this->assertStringContainsString('has 40 slots', $issues[1]['message']);
    }

    public function test_non_list_vector_is_reported(): void
    {
        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => '0,1,2',
            'elo_vector' => ['a' => 5],
        ]));

        $this->assertSame(['relation_vector', 'elo_vector'], array_column($issues, 'field'));
        $this->assertStringContainsString('not a list', $issues[0]['message']);
    }

    public function test_long_lists_of_bad_slots_are_summarised(): void
    {
        $issues = GpfVectorCheck::block($this->block([
            'relation_vector' => array_fill(0, 35, 0.5),
            'elo_vector' => $this->elo(),
        ]));

        $this->assertStringContainsString('and 30 more', $issues[0]['message']);
    }

    public function test_course_walks_every_block_and_names_the_binding_lesson(): void
    {
        $issues = GpfVectorCheck::course([
            'lessons' => [
                ['name' => 'Zlomky', 'blocks' => [['block_id' => 'OK'], ['block_id' => 'BAD']]],
            ],
            'blocks' => [
                ['block_id' => 'OK', 'gpf' => ['relation_vector' => $this->relation([0 => 2]), 'elo_vector' => $this->elo()]],
                ['block_id' => 'BAD', 'gpf' => ['relation_vector' => $this->relation([0 => 1.5]), 'elo_vector' => $this->elo()]],
                ['block_id' => 'ORPHAN', 'gpf' => ['relation_vector' => $this->relation(), 'elo_vector' => $this->elo([5 => 11])]],
            ],
        ]);

        $this->assertSame(
            [['BAD', 'Zlomky', 'relation_vector'], ['ORPHAN', null, 'elo_vector']],
            array_map(fn ($i) => [$i['block_id'], $i['lesson'], $i['field']], $issues),
        );
    }

    public function test_course_without_blocks_has_no_issues(): void
    {
        $this->assertSame([], GpfVectorCheck::course(['lessons' => []]));
    }
}
