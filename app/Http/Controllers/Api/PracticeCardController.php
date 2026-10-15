<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PracticeCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PracticeCardController extends Controller
{
    /**
     * Get all practice cards for the authenticated user.
     * Supports ?since=ISO8601 filter to fetch only cards updated after a timestamp.
     *
     * GET /api/user/practice-cards
     */
    public function index(Request $request): JsonResponse
    {
        $query = PracticeCard::where('user_id', $request->user()->id);

        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $cards = $query->orderBy('due_date', 'asc')->get();

        return response()->json([
            'data' => $cards->map(fn ($c) => [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'course_id' => $c->course_id,
                'lesson_id' => $c->lesson_id,
                'block_id' => $c->block_id,
                'source_type' => $c->source_type,
                'state' => $c->state,
                'due_date' => $c->due_date?->toIso8601String(),
                'stability' => $c->stability,
                'difficulty' => $c->difficulty,
                'reps' => $c->reps,
                'lapses' => $c->lapses,
                'scheduled_days' => $c->scheduled_days,
                'elapsed_days' => $c->elapsed_days,
                'last_review' => $c->last_review?->toIso8601String(),
                'weight' => $c->weight,
                'avg_time_sec' => $c->avg_time_sec,
                'skip_condition' => $c->skip_condition,
                'is_active' => $c->is_active,
                'created_at' => $c->created_at->toIso8601String(),
                'updated_at' => $c->updated_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Bulk upsert practice cards (for offline sync).
     * Upserts on (user_id, block_id) unique constraint.
     *
     * POST /api/user/practice-cards
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cards' => 'required|array|min:1',
            'cards.*.course_id' => 'required|string|max:255',
            // Present but possibly empty: a card seeded by auto-bookmarking a
            // wrong quiz answer has no lesson context. Requiring a non-empty
            // value forced the client to drop those cards from the push, which
            // left them stuck on one device forever (BR-ZBJ99Z). Nullable
            // because Laravel's ConvertEmptyStringsToNull turns "" into null.
            'cards.*.lesson_id' => 'present|nullable|string|max:255',
            'cards.*.block_id' => 'required|string|max:255',
            'cards.*.source_type' => 'required|string|max:255',
            'cards.*.state' => 'nullable|integer',
            'cards.*.due_date' => 'nullable|date',
            'cards.*.stability' => 'nullable|numeric',
            'cards.*.difficulty' => 'nullable|numeric',
            'cards.*.reps' => 'nullable|integer',
            'cards.*.lapses' => 'nullable|integer',
            'cards.*.scheduled_days' => 'nullable|integer',
            'cards.*.elapsed_days' => 'nullable|integer',
            'cards.*.last_review' => 'nullable|date',
            'cards.*.weight' => 'nullable|numeric',
            'cards.*.avg_time_sec' => 'nullable|integer',
            'cards.*.skip_condition' => 'nullable|string|max:255',
            'cards.*.is_active' => 'nullable|boolean',
        ]);

        $userId = $request->user()->id;

        // Collapse duplicate block_ids within a single payload before writing.
        // The table is unique on (user_id, block_id), so a repeated block_id
        // would otherwise cause redundant upserts and report the same card as
        // both "created" and "updated". Last occurrence wins, matching the
        // last-write-wins order of the per-row upserts below.
        $cardsByBlock = [];
        foreach ($validated['cards'] as $entry) {
            $cardsByBlock[$entry['block_id']] = $entry;
        }

        // Upsert the batch atomically so a mid-batch failure can't leave the
        // user's practice deck half-written.
        $results = DB::transaction(function () use ($cardsByBlock, $userId) {
            $out = [];

            foreach ($cardsByBlock as $entry) {
                $existing = PracticeCard::where('user_id', $userId)
                    ->where('block_id', $entry['block_id'])
                    ->first();

                // Descriptive fields always follow the incoming payload.
                $attributes = [
                    'course_id' => $entry['course_id'],
                    'lesson_id' => $entry['lesson_id'] ?? '',
                    'source_type' => $entry['source_type'],
                    'weight' => $entry['weight'] ?? 5.0,
                    'avg_time_sec' => $entry['avg_time_sec'] ?? 20,
                    'skip_condition' => $entry['skip_condition'] ?? null,
                    'is_active' => $entry['is_active'] ?? true,
                ];

                // FSRS scheduling only moves forward. Two devices share one
                // account and each pushes its own copy; a device that has not
                // seen the latest review would otherwise reset the card to
                // "due now" and the review would be lost on both (BR-ZBJ99Z).
                if (! $this->isStaleSchedule($existing, $entry)) {
                    $attributes += [
                        'state' => $entry['state'] ?? 0,
                        'due_date' => $entry['due_date'] ?? now(),
                        'stability' => $entry['stability'] ?? 0.0,
                        'difficulty' => $entry['difficulty'] ?? 0.0,
                        'reps' => $entry['reps'] ?? 0,
                        'lapses' => $entry['lapses'] ?? 0,
                        'scheduled_days' => $entry['scheduled_days'] ?? 0,
                        'elapsed_days' => $entry['elapsed_days'] ?? 0,
                        'last_review' => $entry['last_review'] ?? null,
                    ];
                }

                $card = PracticeCard::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'block_id' => $entry['block_id'],
                    ],
                    $attributes
                );

                $out[] = [
                    'id' => $card->id,
                    'block_id' => $card->block_id,
                    'status' => $card->wasRecentlyCreated ? 'created' : 'updated',
                ];
            }

            return $out;
        });

        return response()->json([
            'data' => $results,
        ], 200);
    }

    /**
     * Whether an incoming card carries an older schedule than the stored one.
     *
     * Compares last_review (the moment the schedule was produced) and falls
     * back to the repetition count when the client sends no review timestamp —
     * a freshly seeded card has reps 0 and no last_review, so it can never
     * clobber a card the account has already practised.
     *
     * @param  array<string, mixed>  $entry
     */
    private function isStaleSchedule(?PracticeCard $existing, array $entry): bool
    {
        if ($existing === null || $existing->last_review === null) {
            return false;
        }

        $incoming = $entry['last_review'] ?? null;

        if ($incoming === null) {
            // No review on the incoming card: only accept it if it claims at
            // least as many repetitions as the stored one.
            return ($entry['reps'] ?? 0) < $existing->reps;
        }

        return $existing->last_review->gt(new \DateTimeImmutable($incoming));
    }
}
