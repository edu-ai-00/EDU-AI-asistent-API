<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenerationSession;
use App\Services\CourseGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI Course Assistant (BR-D4PE8R).
 *
 * Admin routes (behind the `admin` middleware) drive the wizard in the course
 * editor. The two `capture*` routes are PUBLIC by design — the phone that scans
 * the QR code is not logged in. They are protected by the session token itself:
 * unguessable, stored hashed, expiring within minutes, rate limited, and able to
 * do nothing but add a file to one specific session.
 */
class CourseGenerationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Admin
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/admin/generation/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'nullable|string|max:255',
            'course_name' => 'nullable|string|max:255',
        ]);

        ['session' => $session, 'token' => $token] = GenerationSession::issue(
            $validated['course_id'] ?? null,
            $validated['course_name'] ?? null,
        );

        return response()->json([
            'data' => [
                'token' => $token,
                'capture_url' => $this->captureBaseUrl() . '/capture/' . $token,
                'expires_at' => $session->expires_at->toIso8601String(),
                'limits' => $this->limits(),
            ] + $this->present($session),
        ], 201);
    }

    /**
     * Base URL the QR code points at.
     *
     * Same reasoning as ConsentService::baseUrl(): an unset or relative
     * ADMIN_APP_URL would build a QR code that scans perfectly and opens
     * nothing. Whoever holds the phone has no way to tell that apart from a
     * broken feature, so this fails loudly at session creation instead.
     */
    private function captureBaseUrl(): string
    {
        $base = rtrim((string) config('generation.capture_base_url'), '/');

        if (! str_starts_with($base, 'http')) {
            throw new \RuntimeException(
                'generation.capture_base_url must be an absolute http(s) URL. Set ADMIN_APP_URL.'
            );
        }

        return $base;
    }

    /**
     * GET /api/admin/generation/sessions/{token}
     *
     * Polled by the wizard while the phone uploads photos.
     */
    public function show(string $token): JsonResponse
    {
        $session = $this->resolve($token);

        return response()->json(['data' => $this->present($session)]);
    }

    /**
     * POST /api/admin/generation/sessions/{token}/attachments
     */
    public function upload(Request $request, string $token): JsonResponse
    {
        return $this->handleUpload($request, $this->resolve($token));
    }

    /**
     * DELETE /api/admin/generation/sessions/{token}/attachments/{index}
     */
    public function destroyAttachment(string $token, int $index): JsonResponse
    {
        $session = $this->resolve($token);
        $attachments = $session->attachments ?? [];

        if (! array_key_exists($index, $attachments)) {
            return response()->json(['message' => 'Příloha nenalezena.'], 404);
        }

        if (! empty($attachments[$index]['path'])) {
            Storage::disk('s3')->delete($attachments[$index]['path']);
        }

        unset($attachments[$index]);
        $session->update(['attachments' => array_values($attachments)]);

        return response()->json(['data' => $this->present($session->fresh())]);
    }

    /**
     * DELETE /api/admin/generation/sessions/{token}
     *
     * Called when the wizard is closed so uploads do not linger until TTL.
     */
    public function destroy(string $token): JsonResponse
    {
        $session = GenerationSession::findByToken($token);

        if ($session) {
            $session->purgeAttachments();
            $session->delete();
        }

        return response()->json(['data' => (object) [], 'message' => 'Session ukončena.']);
    }

    /**
     * GET /api/admin/generation/sessions/{token}/stream?mode=ocr|full
     *
     * Server-Sent Events. Production runs no queue worker, so generation happens
     * inline on this connection and streams progress as it goes.
     */
    public function stream(Request $request, string $token, CourseGenerationService $service): StreamedResponse
    {
        // The Railway PHP image caps max_execution_time at 30s. A generation
        // run is minutes, so PHP was killing the worker mid-stream with a fatal
        // error — which reaches the browser as a dropped connection carrying no
        // error event at all, i.e. the one failure the client cannot explain.
        //
        // This does NOT make the request unbounded: Guzzle's per-call timeout
        // (300s, see CourseGenerationService::stream) stays the real ceiling.
        //
        // Not reproducible locally — the CLI SAPI defaults max_execution_time
        // to 0, so `php artisan serve` on a laptop never hits this.
        @set_time_limit(0);

        $mode = $request->query('mode') === GenerationSession::MODE_OCR
            ? GenerationSession::MODE_OCR
            : GenerationSession::MODE_FULL;

        $session = GenerationSession::findByToken($token);

        return response()->stream(function () use ($session, $mode, $service) {
            $emit = function (array $payload): void {
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                ob_flush();
                flush();
            };

            $done = function () {
                echo "data: [DONE]\n\n";
                ob_flush();
                flush();
            };

            if (! $session) {
                $emit(['stage' => 'error', 'error' => 'Relace vypršela nebo neexistuje.']);
                $done();

                return;
            }

            if (($session->attachments ?? []) === []) {
                $emit(['stage' => 'error', 'error' => 'Nahrajte alespoň jeden soubor.']);
                $done();

                return;
            }

            // Reconnect: a finished session replays its result instead of
            // burning another generation.
            if ($session->status === GenerationSession::STATUS_DONE && $session->result) {
                $emit(['stage' => 'result', 'result' => $session->result]);
                $done();

                return;
            }

            $session->update([
                'mode' => $mode,
                'status' => GenerationSession::STATUS_GENERATING,
                'error' => null,
            ]);

            try {
                foreach ($service->run($session) as $event) {
                    $emit($event);

                    if (($event['stage'] ?? null) === 'result') {
                        $session->update([
                            'status' => GenerationSession::STATUS_DONE,
                            'result' => $event['result'],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Course generation failed', [
                    'session_id' => $session->id,
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ]);

                $session->update([
                    'status' => GenerationSession::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ]);

                $emit(['stage' => 'error', 'error' => $e->getMessage()]);
            }

            $done();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public — phone capture (no authentication, token-scoped)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/generation/capture/{token}
     */
    public function captureInfo(string $token): JsonResponse
    {
        $session = GenerationSession::findByToken($token);

        if (! $session) {
            return response()->json(['message' => 'Relace vypršela nebo neexistuje.'], 404);
        }

        // Deliberately minimal: enough to render the page, nothing about the
        // course content itself.
        return response()->json([
            'data' => [
                'course_name' => $session->course_name,
                'uploaded' => count($session->attachments ?? []),
                'expires_at' => $session->expires_at->toIso8601String(),
                'limits' => $this->limits(),
            ],
        ]);
    }

    /**
     * POST /api/generation/capture/{token}/attachments
     */
    public function captureUpload(Request $request, string $token): JsonResponse
    {
        $session = GenerationSession::findByToken($token);

        if (! $session) {
            return response()->json(['message' => 'Relace vypršela nebo neexistuje.'], 404);
        }

        $response = $this->handleUpload($request, $session);

        // Never leak the desktop-side view (paths, urls) to the phone.
        if ($response->getStatusCode() < 300) {
            return response()->json([
                'data' => ['uploaded' => count($session->fresh()->attachments ?? [])],
                'message' => 'Nahráno.',
            ], 201);
        }

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared
    // ─────────────────────────────────────────────────────────────────────────

    private function handleUpload(Request $request, GenerationSession $session): JsonResponse
    {
        $files = $request->file('files') ?? $request->file('file');

        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (! is_array($files) || $files === []) {
            return response()->json(['message' => 'Žádný soubor k nahrání.'], 422);
        }

        $existing = count($session->attachments ?? []);
        $max = (int) config('generation.max_attachments');

        if ($existing + count($files) > $max) {
            return response()->json([
                'message' => "Maximálně {$max} příloh na jednu relaci (už nahráno: {$existing}).",
            ], 422);
        }

        $maxBytes = (int) config('generation.max_file_bytes');
        $allowed = config('generation.allowed_mimes');

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return response()->json(['message' => 'Soubor se nepodařilo nahrát.'], 422);
            }

            // getMimeType() sniffs the actual content — the client-sent type is
            // not trusted on a public endpoint.
            if (! in_array($file->getMimeType(), $allowed, true)) {
                return response()->json([
                    'message' => 'Nepodporovaný formát. Povolené jsou JPG, PNG, WEBP a PDF.',
                ], 422);
            }

            if ($file->getSize() > $maxBytes) {
                $mb = round($maxBytes / 1024 / 1024);

                return response()->json(['message' => "Soubor je větší než {$mb} MB."], 422);
            }
        }

        foreach ($files as $file) {
            $extension = $file->guessExtension() ?: 'bin';
            $path = $session->storagePrefix() . '/' . Str::random(16) . '.' . $extension;

            Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), [
                'ContentType' => $file->getMimeType(),
            ]);

            $session->addAttachment([
                'filename' => $file->getClientOriginalName() ?: "capture.{$extension}",
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json(['data' => $this->present($session->fresh())], 201);
    }

    private function resolve(string $token): GenerationSession
    {
        $session = GenerationSession::findByToken($token);

        abort_if($session === null, 404, 'Relace vypršela nebo neexistuje.');

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GenerationSession $session): array
    {
        return [
            'status' => $session->status,
            'mode' => $session->mode,
            'course_id' => $session->course_id,
            'course_name' => $session->course_name,
            'expires_at' => $session->expires_at->toIso8601String(),
            'error' => $session->error,
            'attachments' => array_map(fn (array $a) => [
                'filename' => $a['filename'] ?? '',
                'mime_type' => $a['mime_type'] ?? '',
                'size' => $a['size'] ?? 0,
                'uploaded_at' => $a['uploaded_at'] ?? null,
            ], $session->attachments ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function limits(): array
    {
        return [
            'max_attachments' => (int) config('generation.max_attachments'),
            'max_file_bytes' => (int) config('generation.max_file_bytes'),
            'allowed_mimes' => config('generation.allowed_mimes'),
        ];
    }
}
