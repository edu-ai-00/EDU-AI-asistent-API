<?php

namespace App\Services;

/**
 * Structural fingerprint of a Block V2 course (BR-AYACEK).
 *
 * Language variants of one course must share their teaching logic and differ
 * only in wording. This service reduces a course JSON to that shared skeleton —
 * ids, ordering, didactic metadata, answer correctness, branching — with every
 * piece of translatable prose and every per-variant identity field removed, and
 * hashes the result. Two variants agree when their fingerprints match.
 *
 * Deliberately *out* of the skeleton (may differ between languages):
 *   - prose: name, description, content, hint, help, solution, option text,
 *     option feedback, correct_answer, ai_context, stop_notice, author
 *   - identity: course_id, pin, language, version, status, updated, emoji
 *   - asset URLs, so a variant can ship a localised image; only the *presence*
 *     of a media slot is fingerprinted, not what it points at
 *
 * Deliberately *in* the skeleton, even though it reads like text: gpf.domain /
 * construct / subconstruct and learning.concepts. Those are taxonomy keys, not
 * copy — translating them breaks skill mapping, so blocking on them is a
 * feature, not a false positive.
 */
class CourseStructureFingerprint
{
    /**
     * How many differences diff() reports before it stops listing them.
     */
    private const MAX_DIFF_ENTRIES = 50;

    /**
     * Hash a course's structural skeleton.
     */
    public static function compute(array $course): string
    {
        return hash('sha256', json_encode(
            self::skeleton($course),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Human-readable differences between two courses' skeletons.
     *
     * @return list<string> Empty when the two courses are structurally identical.
     */
    public static function diff(array $left, array $right): array
    {
        $flatLeft = self::flatten(self::skeleton($left));
        $flatRight = self::flatten(self::skeleton($right));

        $paths = array_keys($flatLeft + $flatRight);
        sort($paths);

        $differences = [];

        foreach ($paths as $path) {
            $inLeft = array_key_exists($path, $flatLeft);
            $inRight = array_key_exists($path, $flatRight);

            if ($inLeft && $inRight && $flatLeft[$path] === $flatRight[$path]) {
                continue;
            }

            $differences[] = sprintf(
                '%s: %s → %s',
                $path,
                $inLeft ? self::describe($flatLeft[$path]) : 'chybí',
                $inRight ? self::describe($flatRight[$path]) : 'chybí',
            );

            if (count($differences) >= self::MAX_DIFF_ENTRIES) {
                $differences[] = '… a další rozdíly (výpis zkrácen)';
                break;
            }
        }

        return $differences;
    }

    /**
     * The structural skeleton: everything that must match across languages.
     */
    public static function skeleton(array $course): array
    {
        $lessons = [];
        foreach ($course['lessons'] ?? [] as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $lessonId = (string) ($lesson['lesson_id'] ?? '?');
            $lessons[$lessonId] = self::lessonSkeleton($lesson);
        }
        ksort($lessons);

        $blocks = [];
        foreach ($course['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockId = (string) ($block['block_id'] ?? '?');
            $blocks[$blockId] = self::blockSkeleton($block);
        }
        ksort($blocks);

        return [
            'export_type' => $course['export_type'] ?? null,
            'flags' => [
                'starts_with_quiz' => self::bool($course['starts_with_quiz'] ?? null),
                'only_once' => self::bool($course['only_once'] ?? null),
                'logged_only' => self::bool($course['logged_only'] ?? null),
                'quiz_evaluate' => self::bool($course['quiz_evaluate'] ?? null),
                'only_quiz' => self::bool($course['only_quiz'] ?? null),
                'open_all_lessons' => self::bool($course['open_all_lessons'] ?? null),
                'stop_gambling' => self::bool($course['stop_gambling'] ?? null),
                'max_xp' => self::number($course['max_xp'] ?? null),
            ],
            'lessons' => $lessons,
            'blocks' => $blocks,
        ];
    }

    private static function lessonSkeleton(array $lesson): array
    {
        $bindings = [];
        foreach ($lesson['blocks'] ?? [] as $binding) {
            if (!is_array($binding)) {
                continue;
            }
            $blockId = (string) ($binding['block_id'] ?? '?');
            $bindings[$blockId] = [
                'order' => self::number($binding['order'] ?? null),
                'default_practice' => self::bool($binding['default_practice'] ?? null),
            ];
        }
        ksort($bindings);

        return [
            'order' => self::number($lesson['order'] ?? null),
            'blocks' => $bindings,
        ];
    }

    private static function blockSkeleton(array $block): array
    {
        $steps = [];
        foreach ($block['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepId = (string) ($step['id'] ?? '?');
            $steps[$stepId] = self::stepSkeleton($step);
        }
        ksort($steps);

        return [
            'type' => $block['type'] ?? null,
            'duration' => $block['duration'] ?? null,
            'xp' => self::number($block['xp'] ?? null),
            'default_practice' => self::bool($block['default_practice'] ?? null),
            'gpf' => self::canonical($block['gpf'] ?? null),
            'learning' => self::canonical($block['learning'] ?? null),
            'fsrs' => self::canonical($block['fsrs'] ?? null),
            'adaptation' => self::canonical($block['adaptation'] ?? null),
            'media' => self::mediaSlots($block),
            // Legacy flat question (pre-steps blocks) still carries answer logic.
            'question' => self::questionSkeleton($block['question'] ?? null),
            'steps' => $steps,
        ];
    }

    private static function stepSkeleton(array $step): array
    {
        return [
            'type' => $step['type'] ?? null,
            'order' => self::number($step['order'] ?? null),
            'default_practice' => self::bool($step['default_practice'] ?? null),
            'media' => self::mediaSlots($step),
            'question' => self::questionSkeleton($step['question'] ?? null),
        ];
    }

    /**
     * Which media slots a block or step fills — presence only, never the URL,
     * so a variant may point at a localised asset.
     */
    private static function mediaSlots(array $node): array
    {
        return [
            'image' => !empty($node['image']),
            'video' => !empty($node['video']),
            'audio' => !empty($node['audio']),
        ];
    }

    /**
     * Answer logic: which options exist, which are correct, how they score and
     * where they branch. Option wording and feedback are excluded.
     */
    private static function questionSkeleton(mixed $question): ?array
    {
        if (!is_array($question)) {
            return null;
        }

        $options = [];
        foreach ($question['options'] ?? [] as $index => $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionId = (string) ($option['id'] ?? $index);
            $options[$optionId] = [
                'is_correct' => self::bool($option['is_correct'] ?? null),
                'mark' => isset($option['mark']) ? (string) $option['mark'] : null,
                'score_koef' => self::number($option['score_koef'] ?? null),
                'go_to' => $option['go_to'] ?? null,
            ];
        }
        ksort($options);

        return [
            'type' => $question['type'] ?? null,
            'allow_multiple' => self::bool($question['allow_multiple'] ?? null),
            'allow_photo' => self::bool($question['allow_photo'] ?? null),
            'show_answers' => self::bool($question['show_answers'] ?? null),
            'show_solution' => self::bool($question['show_solution'] ?? null),
            'correct_number' => self::number($question['correct_number'] ?? null),
            'tolerance' => self::number($question['tolerance'] ?? null),
            'options' => $options,
        ];
    }

    /**
     * Deep-sort a subtree so key order in the source JSON cannot change the hash.
     * List arrays keep their order — position is meaningful there (vectors).
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            return self::number($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $canonical = array_map(static fn ($item) => self::canonical($item), $value);

        if (!$isList) {
            ksort($canonical);
        }

        return $canonical;
    }

    /**
     * Normalise numbers so 3 and 3.0 fingerprint identically — hand-edited JSON
     * and editor-exported JSON disagree about that all the time.
     */
    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = $value + 0;

        return (is_float($number) && $number == (int) $number) ? (int) $number : $number;
    }

    /**
     * Normalise booleans so an absent flag and an explicit `false` agree.
     */
    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Flatten a skeleton into dot-paths so diff() can compare leaf by leaf.
     *
     * @return array<string, string>
     */
    private static function flatten(mixed $value, string $prefix = ''): array
    {
        if (!is_array($value) || $value === []) {
            return [$prefix => self::describe($value)];
        }

        $flat = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $flat += self::flatten($child, $path);
        }

        return $flat;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_array($value) => '[]',
            default => (string) $value,
        };
    }
}
