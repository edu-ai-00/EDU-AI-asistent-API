<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnswerEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Formative AI feedback on open answers (BR-FFVVYR, reshaped by BR-P745Q8).
 *
 * Stateless — the request carries the whole task, nothing is looked up
 * server-side and nothing is persisted here. Recording the outcome is the
 * caller's job (quiz attempts, or OpenAnswerEvaluationController for lessons).
 *
 * The one exception is the photo: it is referenced by the handle the upload
 * endpoint issued, and resolved here against the authenticated user's own
 * prefix so a handle cannot be pointed at somebody else's file.
 */
class AnswerEvaluationController extends Controller
{
    public function __construct(private readonly AnswerEvaluationService $evaluator) {}

    /**
     * POST /api/answers/evaluate
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Not `required`. The statement is usually on the question step,
            // but authors routinely put it in a preceding text step and leave
            // the question step's own text empty — the client then had nothing
            // to send. Rejecting that outright produced a 422 the client read
            // as "retry", which could never succeed: an endless retry loop with
            // no feedback. An expected answer or criteria is enough to evaluate
            // against, so require *something* rather than this field.
            'question' => 'sometimes|nullable|string|max:8000',
            'help' => 'sometimes|nullable|string|max:8000',
            'solution' => 'sometimes|nullable|string|max:8000',
            'correct_answer' => 'sometimes|nullable|string|max:8000',
            // Legacy free-text criteria (BR-FFVVYR). Still accepted so courses
            // authored before the structured editor keep grading the same way.
            'criteria' => 'sometimes|nullable|string|max:4000',
            // Structured criteria (BR-P745Q8). Max four, matching the editor.
            'criteria_list' => 'sometimes|nullable|array|max:4',
            'criteria_list.*.id' => 'required|string|max:64',
            'criteria_list.*.name' => 'required|string|max:200',
            'criteria_list.*.description' => 'sometimes|nullable|string|max:1000',
            'criteria_list.*.weight' => 'sometimes|nullable|integer|min:0|max:100',
            // Either text or a photo has to be present — a question with
            // allow_photo may be answered entirely on paper.
            'student_answer' => 'sometimes|nullable|string|max:10000',
            'photo_name' => 'sometimes|nullable|string|max:64',
            'language' => 'sometimes|nullable|string|max:32',
        ]);

        // BR-2RKV3Z asked where the ~10 s a student waits actually goes. The
        // request is timed from here, before the photo is fetched, so the three
        // candidates — R2, the model, everything else — are separable.
        $startedAt = hrtime(true);

        $photo = $this->loadPhoto($request, $validated['photo_name'] ?? null);
        $photoMs = $this->sinceMs($startedAt);

        if (trim((string) ($validated['student_answer'] ?? '')) === '' && $photo === null) {
            return response()->json([
                'message' => 'Chybí odpověď k vyhodnocení.',
            ], 422);
        }

        // Something has to anchor the judgement. With no statement, no expected
        // answer, no solution and no criteria there is nothing to be right or
        // wrong about, and the model would invent a standard.
        // Platform is PHP 8.2 (composer.json), so no array_any().
        $hasAnchor = ! empty($validated['criteria_list']);
        foreach (['question', 'correct_answer', 'solution', 'criteria'] as $key) {
            if (trim((string) ($validated[$key] ?? '')) !== '') {
                $hasAnchor = true;
                break;
            }
        }

        if (! $hasAnchor) {
            return response()->json([
                'message' => 'Chybí zadání i očekávaná odpověď — není podle čeho hodnotit.',
            ], 422);
        }

        $validated['photo'] = $photo;
        unset($validated['photo_name']);

        $result = $this->evaluator->evaluate($validated, $modelTiming);

        $timing = (array) $modelTiming + [
            'photo' => $photoMs,
            'request' => $this->sinceMs($startedAt),
        ];

        // One line per evaluation, so the breakdown can be read off the
        // production logs without waiting for someone to reproduce it. Also
        // logged when the evaluation gave up: a timeout spends the whole
        // budget, and that is the slowest thing this endpoint can do.
        Log::info('Answer evaluation timing', $timing + [
            'model' => $result['model'] ?? null,
            'has_photo' => $photo !== null,
            'outcome' => $result === null ? 'unavailable' : 'ok',
        ]);

        if ($result === null) {
            // 503 rather than 500: this is "try again", not "something is
            // broken with your answer". BR-P745Q8 requires the client to show a
            // retry and log a failed attempt — never to invent a verdict.
            return response()->json([
                'message' => 'AI evaluation unavailable',
            ], 503);
        }

        $result['timing_ms'] = $timing;

        return response()->json(['data' => $result]);
    }

    /** Milliseconds since an `hrtime(true)` mark. */
    private function sinceMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Read the referenced photo, scoped to the authenticated user.
     *
     * A missing or unreadable file degrades to a text-only evaluation rather
     * than an error — the student still gets feedback on whatever they typed.
     *
     * @return array{mime: string, bytes: string}|null
     */
    private function loadPhoto(Request $request, ?string $name): ?array
    {
        $path = AnswerPhotoController::resolve($request->user()->id, $name);

        if ($path === null) {
            return null;
        }

        try {
            $bytes = Storage::disk('s3')->get($path);
        } catch (\Throwable $e) {
            Log::warning('Answer photo unreadable', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return [
            // From the handle, not from the disk: mimeType() is a HEAD request
            // of its own against R2, on the student's critical path, for an
            // answer the upload already sniffed and stored (BR-2RKV3Z).
            'mime' => AnswerPhotoController::mimeFor($path),
            'bytes' => $bytes,
        ];
    }
}
