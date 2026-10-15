<?php

namespace App\Support;

/**
 * GPF vector checks for the app's ELO engine (BR-S9SVNM).
 *
 * The Pilot 2 engine in the app (eduai-app/lib/core/elo/elo_engine.dart)
 * throws on a block whose relation_vector holds anything but exactly 0, 1 or
 * 2, or whose elo_vector leaves 0–10, and the app swallows that — the block
 * just stops moving ELO. The admin now refuses to save such vectors
 * (eduai-admin/src/lib/gpf-vectors.ts, same rules); this is the server-side
 * copy used to find the ones already stored.
 */
class GpfVectorCheck
{
    /** Slots the app's ELO engine expects in every GPF vector. */
    public const VECTOR_LENGTH = 35;

    public const ELO_MIN = 0.0;

    public const ELO_MAX = 10.0;

    /** Offending slots listed in one message before the rest is summarised. */
    private const MAX_LISTED_SLOTS = 5;

    /**
     * Problems in every top-level block of a course's stored JSON, in block
     * order. Each carries the first lesson that binds the block, if any.
     *
     * @return list<array{block_id: string, lesson: ?string, field: string, message: string}>
     */
    public static function course(array $courseData): array
    {
        $lessonByBlock = [];
        foreach ($courseData['lessons'] ?? [] as $lesson) {
            foreach ((is_array($lesson) ? $lesson['blocks'] ?? [] : []) as $binding) {
                $id = is_array($binding) ? ($binding['block_id'] ?? null) : null;
                if (is_string($id) && ! isset($lessonByBlock[$id])) {
                    $lessonByBlock[$id] = is_string($lesson['name'] ?? null) ? $lesson['name'] : null;
                }
            }
        }

        $issues = [];
        foreach ($courseData['blocks'] ?? [] as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $blockId = (string) ($block['block_id'] ?? $block['id'] ?? "#{$index}");
            foreach (self::block($block) as $issue) {
                $issues[] = [
                    'block_id' => $blockId,
                    'lesson' => $lessonByBlock[$blockId] ?? null,
                    ...$issue,
                ];
            }
        }

        return $issues;
    }

    /**
     * Problems with one block's ELO vectors. A block without vectors (or with
     * an empty one) is a display-only block the app skips on purpose.
     *
     * @return list<array{field: string, message: string}>
     */
    public static function block(array $block): array
    {
        $gpf = $block['gpf'] ?? null;
        if (! is_array($gpf)) {
            return [];
        }

        $issues = [];

        if (array_key_exists('relation_vector', $gpf) && $gpf['relation_vector'] !== null) {
            array_push($issues, ...self::vector(
                $gpf['relation_vector'],
                'relation_vector',
                fn ($v) => self::isRelationValue($v),
                'allowed: exactly 0, 1 or 2',
            ));
        }

        if (array_key_exists('elo_vector', $gpf) && $gpf['elo_vector'] !== null) {
            array_push($issues, ...self::vector(
                $gpf['elo_vector'],
                'elo_vector',
                // null is how the engine marks an unrated slot.
                fn ($v) => $v === null || self::isEloRating($v),
                'allowed: '.self::ELO_MIN.'–'.self::ELO_MAX.' or null',
            ));
        }

        return $issues;
    }

    /**
     * A numeric string counts as the number it spells: the app parses every
     * slot with double.tryParse before its engine runs, and EloEngine casts
     * with (float), so courses that store "2" for 2 work (BR-6DRK92).
     */
    public static function isRelationValue(mixed $value): bool
    {
        return is_numeric($value)
            && in_array((float) $value, [0.0, 1.0, 2.0], true);
    }

    /** Numeric strings count, as for isRelationValue. */
    public static function isEloRating(mixed $value): bool
    {
        return is_numeric($value)
            && is_finite((float) $value)
            && (float) $value >= self::ELO_MIN
            && (float) $value <= self::ELO_MAX;
    }

    /**
     * @param  callable(mixed): bool  $isValid
     * @return list<array{field: string, message: string}>
     */
    private static function vector(mixed $vector, string $field, callable $isValid, string $allowed): array
    {
        if (! is_array($vector) || ! array_is_list($vector)) {
            return [['field' => $field, 'message' => "{$field} is not a list of numbers."]];
        }

        // The app treats an empty vector like a missing one.
        if ($vector === []) {
            return [];
        }

        $issues = [];

        if (count($vector) !== self::VECTOR_LENGTH) {
            $issues[] = [
                'field' => $field,
                'message' => sprintf('%s has %d slots; the ELO engine needs exactly %d.', $field, count($vector), self::VECTOR_LENGTH),
            ];
        }

        $bad = [];
        foreach ($vector as $index => $value) {
            if (! $isValid($value)) {
                $bad[] = "[{$index}] = ".json_encode($value);
            }
        }

        if ($bad !== []) {
            $listed = implode(', ', array_slice($bad, 0, self::MAX_LISTED_SLOTS));
            $rest = count($bad) > self::MAX_LISTED_SLOTS
                ? sprintf(' and %d more', count($bad) - self::MAX_LISTED_SLOTS)
                : '';
            $issues[] = [
                'field' => $field,
                'message' => "{$field}: invalid values {$listed}{$rest} — {$allowed}.",
            ];
        }

        return $issues;
    }
}
