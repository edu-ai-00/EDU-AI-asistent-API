<?php

namespace App\Services;

use App\Models\BlockStat;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Server-side calibration of task difficulty D (BR-697NCA).
 *
 * The Pilot 2 engine moves the student's rating L and the task's difficulty D
 * on every answer. The app keeps computing L itself — offline-first, instant
 * feedback — but D is shared by every student, so the server is its only
 * writer: each interaction the app logs is applied here, one answer at a time
 * per block, under a row lock. Design:
 * docs/superpowers/specs/2026-09-26-server-task-difficulty-calibration-design.md,
 * with the decisions recorded on BR-QF2N45:
 *
 * - D1  one D per block_id, shared across schools, classes and language
 *       variants (they share block_id and gpf by construction).
 * - D2  effective D = the calibrated value where a dimension has one, else
 *       the course's own elo_vector. An author's later edit of an already
 *       calibrated dimension does not reset it — that is the editor's call.
 * - D3  only students (guests included) move D; staff answers are logged.
 * - D4  only a student's first attempt at a block moves D — retries follow
 *       feedback and would bias it downwards. L still moves on every attempt.
 * - D5  calibrated D stays in block_stats, never written back into content.
 * - D6  rows from app builds without profil_elo_before are logged but not
 *       applied live; recalibration folds them in by reconstructing L.
 *
 * Live application runs in arrival order — an online approximation, since
 * offline batches can arrive days late. recalibrateBlock() is the exact
 * answer: it replays every answer to the block in answer order. For the same
 * order the two agree to the last digit.
 */
class TaskCalibrationService
{
    /** ELO_TASK_CALIBRATION is off. */
    public const SKIP_DISABLED = 'disabled';

    /** Answer by a teacher or admin (D3). */
    public const SKIP_ROLE = 'role';

    /** The block has no dimension the engine updates (relation 2 with a D). */
    public const SKIP_NO_ACTIVE_DIMENSION = 'no_active_dimension';

    /** Not the student's first attempt at this block (D4). */
    public const SKIP_REPEAT_ATTEMPT = 'repeat_attempt';

    /** Sent without profil_elo_before by an older build (D6). */
    public const SKIP_LEGACY_PAYLOAD = 'legacy_payload';

    /** The student was unrated in every active dimension: L is only seeded. */
    public const SKIP_FIRST_CONTACT = 'first_contact';

    /** Answer order: when the student confirmed the answer, else when it arrived. */
    private const ANSWERED_AT = 'COALESCE(confirmed_at, created_at)';

    /** @var array<string, array<string, array{relation_vector: list<float>, elo_vector: list<float|null>}>> */
    private array $courseBlocks = [];

    /** @var array<string, string>|null block_id → course_id holding it */
    private ?array $blockCourseIndex = null;

    public static function enabled(): bool
    {
        return (bool) config('elo.task_calibration');
    }

    /**
     * Effective difficulty of a block (D2): the calibrated value per
     * dimension where there is one, the course's elo_vector elsewhere.
     *
     * @param  array<int, mixed>  $prior  the block's elo_vector from course content
     * @param  array<int, mixed>|null  $calibrated  block_stats.elo_vector
     * @return list<float|null>
     */
    public static function effective(array $prior, ?array $calibrated): array
    {
        $prior = self::ratings($prior);
        $calibrated = self::ratings($calibrated ?? []);

        return array_map(fn ($c, $p) => $c ?? $p, $calibrated, $prior);
    }

    /**
     * Calibrated D the app may use, per block — none while the calibration
     * is switched off, so every consumer falls back to the course content.
     *
     * @param  list<string>  $blockIds
     * @return array<string, array<int, mixed>|null>
     */
    public function publishedDifficulties(array $blockIds): array
    {
        if (! self::enabled() || $blockIds === []) {
            return [];
        }

        return BlockStat::whereIn('block_id', $blockIds)
            ->get(['block_id', 'elo_vector'])
            ->mapWithKeys(fn (BlockStat $s) => [$s->block_id => $s->elo_vector])
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Live path
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Store one interaction from the app and apply its task half when it is
     * eligible. Each call is its own transaction, so a batch that fails
     * halfway never applies an answer twice when the app re-sends it.
     *
     * @param  array<string, mixed>  $attributes  EloInteraction attributes the app sent
     * @return array{0: EloInteraction, 1: bool}  the row, and false when it
     *                                            already existed (a retried send)
     */
    public function record(User $user, array $attributes): array
    {
        $uuid = $attributes['client_uuid'] ?? null;
        if ($uuid !== null && ($existing = $this->findSent($user->id, $uuid)) !== null) {
            return [$existing, false];
        }

        try {
            return [DB::transaction(fn () => $this->store($user, $attributes)), true];
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry of the same answer committed first.
            $existing = $uuid !== null ? $this->findSent($user->id, $uuid) : null;
            if ($existing === null) {
                throw $e;
            }

            return [$existing, false];
        }
    }

    private function findSent(int $userId, string $uuid): ?EloInteraction
    {
        return EloInteraction::where('user_id', $userId)->where('client_uuid', $uuid)->first();
    }

    private function store(User $user, array $attributes): EloInteraction
    {
        $blockId = (string) $attributes['block_id'];
        $gpf = $this->blockGpf($attributes['course_id'] ?? null, $blockId);
        $lBefore = $attributes['profil_elo_before'] ?? null;

        $reason = match (true) {
            ! self::enabled() => self::SKIP_DISABLED,
            $user->isAdminOrTeacher() => self::SKIP_ROLE,
            $gpf === null => self::SKIP_NO_ACTIVE_DIMENSION,
            default => null,
        };

        // Eligible so far → take the block's row lock before anything reads
        // its state, so concurrent answers to the block apply one by one.
        $stat = $reason === null
            ? $this->lockStat($blockId)
            : BlockStat::where('block_id', $blockId)->first();
        $state = self::state($stat);

        if ($reason === null) {
            if (EloInteraction::where('user_id', $user->id)->where('block_id', $blockId)->exists()) {
                $reason = self::SKIP_REPEAT_ATTEMPT;
            } elseif (! is_array($lBefore)) {
                $reason = self::SKIP_LEGACY_PAYLOAD;
            } else {
                $reason = $this->applyTaskHalf($state, $gpf, $lBefore, (float) $attributes['score']);
                $stat->forceFill($state)->save();
            }
        }

        return EloInteraction::create([
            ...$attributes,
            'user_id' => $user->id,
            'elo_vector_snapshot' => $gpf !== null ? self::effective($gpf['elo_vector'], $state['elo_vector']) : null,
            'task_applied' => $reason === null,
            'task_skip_reason' => $reason,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Recalibration
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Recalibrate the blocks of a course, $limit of them from $offset on (the
     * admin endpoint pages through a course so each request stays well under
     * Cloudflare's 120 s cap). Blocks already listed in $done — the language
     * variant of a course processed earlier in the same run — are skipped.
     *
     * @param  array<string, true>  $done
     * @return array{blocks_total: int, blocks_processed: int, next_offset: int, done: bool, interactions: int, applied: int, changed: int}
     */
    public function recalibrateCourse(
        Course $course,
        int $offset = 0,
        ?int $limit = null,
        bool $dryRun = false,
        array &$done = [],
    ): array {
        $blocks = self::extractBlockGpf(is_array($course->data) ? $course->data : []);
        $slice = array_slice($blocks, $offset, $limit, true);

        $totals = ['interactions' => 0, 'applied' => 0, 'changed' => 0];
        $run = function () use ($slice, &$totals, &$done) {
            foreach ($slice as $blockId => $gpf) {
                if (isset($done[$blockId])) {
                    continue;
                }
                foreach ($this->recalibrateBlock((string) $blockId, $gpf) as $key => $count) {
                    $totals[$key] += $count;
                }
                $done[$blockId] = true;
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            $run();
        }

        $nextOffset = $offset + count($slice);

        return [
            'blocks_total' => count($blocks),
            'blocks_processed' => count($slice),
            'next_offset' => $nextOffset,
            'done' => $nextOffset >= count($blocks),
            ...$totals,
        ];
    }

    /**
     * Rebuild one block's calibration from scratch: D := the course's
     * elo_vector, m := 0, then every answer to the block — all students, all
     * language variants, the global quiz — in answer order, under the same
     * eligibility rules as the live path. Rewrites each row's task_applied,
     * task_skip_reason and elo_vector_snapshot.
     *
     * @param  array{relation_vector: array, elo_vector: array}  $gpf
     * @return array{interactions: int, applied: int, changed: int}
     */
    public function recalibrateBlock(string $blockId, array $gpf): array
    {
        return DB::transaction(function () use ($blockId, $gpf) {
            // A block nobody has answered gets no row — unless it has one
            // already (its answers were erased since), which is reset below.
            $stat = EloInteraction::where('block_id', $blockId)->exists()
                ? $this->lockStat($blockId)
                : BlockStat::where('block_id', $blockId)->lockForUpdate()->first();
            if ($stat === null) {
                return ['interactions' => 0, 'applied' => 0, 'changed' => 0];
            }
            $state = self::state(null);

            $rows = EloInteraction::where('block_id', $blockId)
                ->select('*')
                ->selectRaw(self::ANSWERED_AT.' AS answered_at')
                ->orderByRaw(self::ANSWERED_AT)
                ->orderBy('id')
                ->get();

            $staff = User::whereIn('id', $rows->pluck('user_id')->unique())
                ->whereIn('role', ['admin', 'teacher'])
                ->pluck('id')
                ->flip();

            $seen = [];
            $applied = 0;
            $changed = 0;

            foreach ($rows as $row) {
                if (isset($staff[$row->user_id])) {
                    $reason = self::SKIP_ROLE;
                } elseif (isset($seen[$row->user_id])) {
                    $reason = self::SKIP_REPEAT_ATTEMPT;
                } else {
                    $seen[$row->user_id] = true;
                    $lBefore = $row->profil_elo_before ?? $this->lBeforeFromHistory($row);
                    $reason = $this->applyTaskHalf($state, $gpf, $lBefore, (float) $row->score);
                }

                $applied += $reason === null ? 1 : 0;
                $row->forceFill([
                    'task_applied' => $reason === null,
                    'task_skip_reason' => $reason,
                    'elo_vector_snapshot' => self::effective($gpf['elo_vector'], $state['elo_vector']),
                ]);
                if ($row->isDirty()) {
                    $row->save();
                    $changed++;
                }
            }

            $stat->forceFill($state)->save();

            return ['interactions' => $rows->count(), 'applied' => $applied, 'changed' => $changed];
        });
    }

    /**
     * L before an answer from an app build that did not send it (D6): the L
     * after the student's previous answer anywhere, in answer order; unrated
     * for their first. Exact as long as every rating change went through an
     * interaction — approximate after a reinstall or a device switch.
     *
     * @return array<int, mixed>
     */
    private function lBeforeFromHistory(EloInteraction $row): array
    {
        $at = $row->getAttribute('answered_at');

        $previous = EloInteraction::where('user_id', $row->user_id)
            ->where(function ($q) use ($at, $row) {
                $q->whereRaw(self::ANSWERED_AT.' < ?', [$at])
                    ->orWhere(function ($q) use ($at, $row) {
                        $q->whereRaw(self::ANSWERED_AT.' = ?', [$at])->where('id', '<', $row->id);
                    });
            })
            ->orderByRaw(self::ANSWERED_AT.' DESC')
            ->orderByDesc('id')
            ->first(['id', 'profil_elo_snapshot']);

        return $previous?->profil_elo_snapshot ?? [];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // The task half
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Apply the task half of one answer to a block's state, in place. The
     * student half of the engine's result is discarded — L is the app's.
     *
     * @param  array{elo_vector: list<float|null>, item_pocet: list<int>}  $state
     * @param  array{relation_vector: array, elo_vector: array}  $gpf
     * @param  array<int, mixed>  $lBefore
     * @return string|null  null when D moved, else why it did not
     */
    private function applyTaskHalf(array &$state, array $gpf, array $lBefore, float $score): ?string
    {
        $result = EloEngine::updateTask(
            self::ratings($lBefore),
            array_fill(0, EloEngine::GPF_DIMENSIONS, 0),
            self::relations($gpf['relation_vector']),
            self::effective($gpf['elo_vector'], $state['elo_vector']),
            $state['item_pocet'],
            $score,
        );

        // The engine bumps m exactly where it moved D; first contact only
        // seeds L and leaves both alone.
        $moved = false;
        foreach ($result['item_pocet'] as $k => $count) {
            if ((int) $count !== $state['item_pocet'][$k]) {
                $state['elo_vector'][$k] = (float) $result['elo_vector'][$k];
                $state['item_pocet'][$k] = (int) $count;
                $moved = true;
            }
        }

        if ($moved) {
            return null;
        }

        return $result['updated_indices'] === [] ? self::SKIP_NO_ACTIVE_DIMENSION : self::SKIP_FIRST_CONTACT;
    }

    /**
     * The block's row, created empty when missing, locked for this
     * transaction. Serialises answers to the same block (a row lock on
     * Postgres; SQLite serialises writers anyway) while other blocks proceed.
     */
    private function lockStat(string $blockId): BlockStat
    {
        $now = now();
        BlockStat::insertOrIgnore(['block_id' => $blockId, 'created_at' => $now, 'updated_at' => $now]);

        return BlockStat::where('block_id', $blockId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array{elo_vector: list<float|null>, item_pocet: list<int>}
     */
    private static function state(?BlockStat $stat): array
    {
        return [
            'elo_vector' => self::ratings($stat?->elo_vector ?? []),
            'item_pocet' => self::counts($stat?->item_pocet ?? []),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Block GPF from the server's copy of the course
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The GPF vectors of a block, from the server's copy of the course — the
     * client never chooses which dimensions move. The global quiz logs under
     * a sentinel course_id (GLOBAL_QUIZ), and a block can outlive the course
     * it was logged under, so an unknown pair falls back to any course that
     * holds the block.
     *
     * @return array{relation_vector: list<float>, elo_vector: list<float|null>}|null
     */
    public function blockGpf(?string $courseId, string $blockId): ?array
    {
        if ($courseId !== null && ($gpf = $this->courseBlocks($courseId)[$blockId] ?? null) !== null) {
            return $gpf;
        }

        $owner = $this->blockCourseIndex()[$blockId] ?? null;

        return $owner !== null ? ($this->courseBlocks($owner)[$blockId] ?? null) : null;
    }

    /**
     * Block ids of a course that carry GPF vectors.
     *
     * @return list<string>
     */
    public function courseBlockIds(string $courseId): array
    {
        return array_map('strval', array_keys($this->courseBlocks($courseId)));
    }

    /**
     * @return array<string, array{relation_vector: list<float>, elo_vector: list<float|null>}>
     */
    private function courseBlocks(string $courseId): array
    {
        if (isset($this->courseBlocks[$courseId])) {
            return $this->courseBlocks[$courseId];
        }

        $course = Course::where('course_id', $courseId)->first(['id', 'course_id', 'version']);
        if ($course === null) {
            return $this->courseBlocks[$courseId] = [];
        }

        // Keyed by version, which every save bumps: the full course JSON is
        // loaded once per version, not once per answer.
        return $this->courseBlocks[$courseId] = Cache::remember(
            "elo:course-gpf:{$course->course_id}:v{$course->version}",
            now()->addDay(),
            function () use ($course) {
                $data = Course::whereKey($course->id)->first(['id', 'data'])?->data;

                return self::extractBlockGpf(is_array($data) ? $data : []);
            }
        );
    }

    /**
     * @return array<string, string>
     */
    private function blockCourseIndex(): array
    {
        if ($this->blockCourseIndex !== null) {
            return $this->blockCourseIndex;
        }

        $stamp = Course::whereNotNull('data')->selectRaw('COUNT(*) AS n, MAX(updated_at) AS latest')->first();

        return $this->blockCourseIndex = Cache::remember(
            'elo:block-course-index:'.md5($stamp?->n.'|'.$stamp?->latest),
            now()->addDay(),
            function () {
                $index = [];
                // In chunks: `data` is the full course JSON.
                foreach (Course::whereNotNull('data')->select(['id', 'course_id', 'data'])->lazyById(20, 'id') as $course) {
                    foreach (array_keys(self::extractBlockGpf(is_array($course->data) ? $course->data : [])) as $blockId) {
                        $index[(string) $blockId] ??= $course->course_id;
                    }
                }

                return $index;
            }
        );
    }

    /**
     * Blocks of a course JSON that carry both GPF vectors, in content order.
     * An empty or missing vector marks a display-only block the app never
     * rates. Vectors are fitted to the engine's 35 slots.
     *
     * @return array<string, array{relation_vector: list<float>, elo_vector: list<float|null>}>
     */
    public static function extractBlockGpf(array $courseData): array
    {
        $candidates = [];
        foreach ($courseData['blocks'] ?? [] as $block) {
            if (is_array($block)) {
                $candidates[] = $block;
            }
        }
        // Older content keeps gpf on the lesson binding instead.
        foreach ($courseData['lessons'] ?? [] as $lesson) {
            foreach ((is_array($lesson) ? $lesson['blocks'] ?? [] : []) as $binding) {
                if (is_array($binding)) {
                    $candidates[] = $binding;
                }
            }
        }

        $blocks = [];
        foreach ($candidates as $block) {
            $id = $block['block_id'] ?? $block['id'] ?? null;
            $gpf = $block['gpf'] ?? null;
            if (! is_string($id) || $id === '' || isset($blocks[$id]) || ! is_array($gpf)) {
                continue;
            }
            $relation = $gpf['relation_vector'] ?? null;
            $elo = $gpf['elo_vector'] ?? null;
            if (! is_array($relation) || $relation === [] || ! is_array($elo) || $elo === []) {
                continue;
            }
            $blocks[$id] = [
                'relation_vector' => self::relations($relation),
                'elo_vector' => self::ratings($elo),
            ];
        }

        return $blocks;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Vector shape
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * @return list<float|null>
     */
    private static function ratings(array $vector): array
    {
        $out = [];
        for ($k = 0; $k < EloEngine::GPF_DIMENSIONS; $k++) {
            $v = $vector[$k] ?? null;
            $out[] = is_numeric($v) ? (float) $v : null;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function counts(array $vector): array
    {
        $out = [];
        for ($k = 0; $k < EloEngine::GPF_DIMENSIONS; $k++) {
            $v = $vector[$k] ?? 0;
            $out[] = is_numeric($v) ? (int) $v : 0;
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    private static function relations(array $vector): array
    {
        $out = [];
        for ($k = 0; $k < EloEngine::GPF_DIMENSIONS; $k++) {
            $v = $vector[$k] ?? 0;
            $out[] = is_numeric($v) ? (float) $v : 0.0;
        }

        return $out;
    }
}
