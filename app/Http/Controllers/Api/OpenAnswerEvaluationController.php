<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpenAnswerEvaluation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persistence for open answers given outside quiz mode (BR-FFVVYR).
 *
 * Grading itself happens in AnswerEvaluationController; this only records the
 * outcome so a teacher can see what the student wrote and how it was scored.
 *
 * BR-P745Q8 made each submission an *attempt* rather than a latest-value:
 * retries append instead of overwriting, failed evaluations are stored rather
 * than swallowed, and every row carries a snapshot of what it was judged
 * against so editing a question does not rewrite its history.
 */
class OpenAnswerEvaluationController extends Controller
{
    /**
     * Store a batch of graded open answers.
     *
     * POST /api/user/open-answers
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array|min:1|max:100',
            'answers.*.client_uuid' => 'required|uuid',
            'answers.*.course_id' => 'required|string|max:255',
            'answers.*.lesson_id' => 'sometimes|nullable|string|max:255',
            'answers.*.block_id' => 'required|string|max:255',
            'answers.*.step_id' => 'sometimes|nullable|string|max:255',
            // Empty when the answer was given entirely as a photo (BR-P745Q8).
            'answers.*.answer_text' => 'present|string|max:10000',
            'answers.*.is_correct' => 'sometimes|boolean',
            'answers.*.ai_score' => 'sometimes|nullable|numeric|min:0|max:1',
            'answers.*.ai_feedback' => 'sometimes|nullable|string|max:2000',
            'answers.*.evaluated_by_ai' => 'sometimes|boolean',
            'answers.*.ai_model' => 'sometimes|nullable|string|max:255',
            'answers.*.answered_at' => 'sometimes|nullable|date',
            // ─── BR-P745Q8 ───────────────────────────────────────────────────
            'answers.*.feedback_parts' => 'sometimes|nullable|array',
            'answers.*.feedback_parts.strengths' => 'sometimes|nullable|string|max:1200',
            'answers.*.feedback_parts.improvements' => 'sometimes|nullable|string|max:1200',
            'answers.*.feedback_parts.next_step' => 'sometimes|nullable|string|max:1200',
            'answers.*.feedback_parts.hint_question' => 'sometimes|nullable|string|max:1200',
            'answers.*.criteria_scores' => 'sometimes|nullable|array|max:4',
            'answers.*.criteria_scores.*.id' => 'required|string|max:64',
            'answers.*.criteria_scores.*.name' => 'sometimes|nullable|string|max:200',
            'answers.*.criteria_scores.*.score' => 'required|numeric|min:0|max:1',
            'answers.*.grading_basis' => 'sometimes|nullable|array',
            // No `shadow`: with AI evaluation off nothing evaluates an answer
            // any more (BR-WBY5AF), so a client offering one is out of date.
            'answers.*.attempt_status' => 'sometimes|string|in:evaluated,failed',
            'answers.*.photo_name' => 'sometimes|nullable|string|max:64',
            'answers.*.prompt_version' => 'sometimes|nullable|string|max:40',
        ]);

        $userId = $request->user()->id;
        $saved = 0;

        foreach ($validated['answers'] as $answer) {
            if (($answer['answer_text'] ?? '') === '' && ($answer['photo_name'] ?? null) === null) {
                // Nothing to show a teacher. Skipping rather than failing the
                // batch: the client replays whole queues and one junk entry
                // must not block the rest.
                continue;
            }

            // Idempotent on (user_id, client_uuid) — the offline-first client
            // replays its queue, and a replay must not create a second row.
            $existing = OpenAnswerEvaluation::where('user_id', $userId)
                ->where('client_uuid', $answer['client_uuid'])
                ->first();

            $attributes = [
                'course_id' => $answer['course_id'],
                'lesson_id' => $answer['lesson_id'] ?? null,
                'block_id' => $answer['block_id'],
                'step_id' => $answer['step_id'] ?? null,
                'answer_text' => $answer['answer_text'],
                'is_correct' => $answer['is_correct'] ?? false,
                'ai_score' => $answer['ai_score'] ?? null,
                'ai_feedback' => $answer['ai_feedback'] ?? null,
                'evaluated_by_ai' => $answer['evaluated_by_ai'] ?? false,
                'ai_model' => $answer['ai_model'] ?? null,
                'feedback_parts' => $answer['feedback_parts'] ?? null,
                'criteria_scores' => $answer['criteria_scores'] ?? null,
                'grading_basis' => $answer['grading_basis'] ?? null,
                'attempt_status' => $answer['attempt_status'] ?? OpenAnswerEvaluation::STATUS_EVALUATED,
                'prompt_version' => $answer['prompt_version'] ?? null,
                'photo_path' => isset($answer['photo_name'])
                    ? AnswerPhotoController::resolve($userId, $answer['photo_name'])
                    : null,
                'answered_at' => isset($answer['answered_at'])
                    ? Carbon::parse($answer['answered_at'])->utc()
                    : now(),
            ];

            if ($existing !== null) {
                // A replay. attempt_number stays put — renumbering on every
                // retry of the sync queue would scramble the history.
                $existing->update($attributes);
            } else {
                OpenAnswerEvaluation::create($attributes + [
                    'user_id' => $userId,
                    'client_uuid' => $answer['client_uuid'],
                    'attempt_number' => $this->attemptNumberFor($userId, $answer),
                ]);
            }

            $saved++;
        }

        return response()->json(['saved' => $saved], 201);
    }

    /**
     * Position of this attempt among the student's attempts at the same
     * question. A failed evaluation counts — the brief is explicit that a retry
     * must not erase the attempt that failed.
     *
     * Two attempts submitted concurrently can land on the same number. That is
     * tolerated: the number orders a teacher's view, it is not an identity.
     *
     * @param  array<string, mixed>  $answer
     */
    private function attemptNumberFor(int $userId, array $answer): int
    {
        $max = (int) OpenAnswerEvaluation::where('user_id', $userId)
            ->where('course_id', $answer['course_id'])
            ->where('block_id', $answer['block_id'])
            ->where('step_id', $answer['step_id'] ?? null)
            ->max('attempt_number');

        return $max + 1;
    }

    /**
     * Open answers of the authenticated user. Supports ?course_id= and
     * ?block_id= filters.
     *
     * GET /api/user/open-answers
     *
     * This is what restores an attempt after a reload on a device that has no
     * local copy — a new phone, a cleared browser. The offline-first client
     * normally answers from its own Drift database.
     */
    public function index(Request $request): JsonResponse
    {
        // Shadow rows are excluded: the student was never shown that verdict,
        // and restoring one onto a new device would replace the feedback they
        // actually got with one they never saw. Nothing writes them any more
        // (BR-WBY5AF) — this keeps the ones already stored out of sight.
        $query = OpenAnswerEvaluation::where('user_id', $request->user()->id)
            ->where('attempt_status', '!=', OpenAnswerEvaluation::STATUS_SHADOW);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        if ($request->filled('block_id')) {
            $query->where('block_id', $request->input('block_id'));
        }

        $rows = $query->orderBy('answered_at', 'desc')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->format($r, forStudent: true)),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Admin endpoints
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/open-answers/{courseId}
     */
    public function adminByCourse(string $courseId): JsonResponse
    {
        $rows = OpenAnswerEvaluation::with('user:id,name,email', 'user.classrooms:classrooms.id,name')
            ->where('course_id', $courseId)
            ->orderBy('answered_at', 'desc')
            ->limit(1000)
            ->get();

        return response()->json([
            'course_id' => $courseId,
            'data' => $rows->map(function ($r) {
                $base = $this->format($r);
                $base['user'] = $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                    'classroom_ids' => $r->user->classrooms->pluck('id')->all(),
                    'classroom_name' => $r->user->classroomNames() ?: null,
                ] : null;

                return $base;
            }),
            'meta' => [
                'total_answers' => $rows->count(),
                'unique_users' => $rows->pluck('user_id')->unique()->count(),
                'ai_evaluated' => $rows->where('evaluated_by_ai', true)->count(),
                'failed_attempts' => $rows->where('attempt_status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/open-answers/user/{userId}
     */
    public function adminByUser(Request $request, int $userId): JsonResponse
    {
        $query = OpenAnswerEvaluation::where('user_id', $userId);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $rows = $query->orderBy('answered_at', 'desc')->limit(1000)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->format($r)),
            'meta' => [
                'total_answers' => $rows->count(),
                'ai_evaluated' => $rows->where('evaluated_by_ai', true)->count(),
                'failed_attempts' => $rows->where('attempt_status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * Serialize one attempt.
     *
     * `$forStudent` is the enforcement point for the rule the brief cares most
     * about after not leaking the expected answer: the internal score and the
     * pass/fail flag are for the teacher, ELO and FSRS — never for the student.
     * Keeping that here rather than only in the app means a future client
     * cannot render a grade by accident.
     *
     * @return array<string, mixed>
     */
    private function format(OpenAnswerEvaluation $r, bool $forStudent = false): array
    {
        $base = [
            'id' => $r->id,
            'course_id' => $r->course_id,
            'lesson_id' => $r->lesson_id,
            'block_id' => $r->block_id,
            'step_id' => $r->step_id,
            'answer_text' => $r->answer_text,
            'feedback_parts' => $r->feedback_parts,
            'ai_feedback' => $r->ai_feedback,
            'evaluated_by_ai' => $r->evaluated_by_ai,
            'attempt_status' => $r->attempt_status,
            'attempt_number' => $r->attempt_number,
            'has_photo' => $r->photo_path !== null,
            'answered_at' => $r->answered_at?->toIso8601String(),
        ];

        if ($forStudent) {
            return $base;
        }

        return $base + [
            'is_correct' => $r->is_correct,
            'ai_score' => $r->ai_score,
            'criteria_scores' => $r->criteria_scores,
            // What this attempt was judged against at the time. Lets a teacher
            // read an old attempt correctly after editing the question.
            'grading_basis' => $r->grading_basis,
            'ai_model' => $r->ai_model,
            'prompt_version' => $r->prompt_version,
            'teacher_override_score' => $r->teacher_override_score,
            'photo_url' => $r->photo_path !== null
                ? "/api/admin/open-answers/{$r->id}/photo"
                : null,
        ];
    }
}
