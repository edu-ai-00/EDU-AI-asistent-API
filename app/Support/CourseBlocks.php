<?php

namespace App\Support;

/**
 * One entry per block_id in a course's top-level `blocks` array (BR-XU4Y5K).
 *
 * Lessons reference blocks by id, so a second entry with the same block_id is
 * never reachable on its own — but nothing stopped a course JSON carrying one,
 * and it was stored verbatim (A2-Rovnice_c1 held a whole lesson's blocks
 * twice), which is what every export then showed. The app plays the first
 * match (eduai-app/lib/models/block_model.dart), so that is the copy kept;
 * the admin editor drops the rest the same way when it loads a course
 * (eduai-admin/src/lib/course-blocks.ts).
 */
class CourseBlocks
{
    /**
     * The course with every repeated block_id after its first occurrence
     * removed. Entries without a block_id are left alone; a course with no
     * repeats comes back untouched.
     */
    public static function unique(array $courseData): array
    {
        if (!isset($courseData['blocks']) || !is_array($courseData['blocks'])) {
            return $courseData;
        }

        $seen = [];
        $blocks = [];
        foreach ($courseData['blocks'] as $block) {
            $blockId = is_array($block) ? ($block['block_id'] ?? null) : null;
            if (is_string($blockId) && $blockId !== '') {
                if (isset($seen[$blockId])) {
                    continue;
                }
                $seen[$blockId] = true;
            }
            $blocks[] = $block;
        }

        if (count($blocks) === count($courseData['blocks'])) {
            return $courseData;
        }

        $courseData['blocks'] = $blocks;

        return $courseData;
    }
}
