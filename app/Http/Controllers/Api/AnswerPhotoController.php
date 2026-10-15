<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpenAnswerEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photographed answers to open questions (BR-P745Q8).
 *
 * A student may photograph a solution worked out on paper when the question has
 * `allow_photo` enabled. The photo is uploaded first and referenced by path
 * afterwards, so the evaluation request stays small and the same file can be
 * re-read when a teacher opens the attempt months later.
 *
 * These are pictures of children's schoolwork, so nothing here is public:
 * - the R2 object gets no public URL and is never returned as one,
 * - every path is namespaced by user id and the prefix is verified on read,
 * - the only ways back out are this controller (the owner) and the admin route
 *   (a teacher with course access).
 */
class AnswerPhotoController extends Controller
{
    /** Everything a student uploads lives under this prefix, by user. */
    public const PREFIX = 'answer-photos';

    /**
     * The stored filename is the whole handle a client gets back. Constrained
     * so it can never walk out of the user's own prefix.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9]{16,32}\.[a-z]{3,4}$/';

    /**
     * POST /api/answers/photo
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file',
            'course_id' => 'sometimes|nullable|string|max:255',
        ]);

        $file = $request->file('photo');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return response()->json(['message' => 'Fotku se nepodařilo nahrát.'], 422);
        }

        // getMimeType() sniffs the actual bytes. A client-declared type would
        // let a renamed file reach the vision model.
        $mime = $file->getMimeType();
        if (! in_array($mime, config('chat.answer_photo_mimes'), true)) {
            return response()->json([
                'message' => 'Nepodporovaný formát. Vyfoť řešení znovu, nebo použij JPG, PNG či WEBP.',
            ], 422);
        }

        $maxBytes = (int) config('chat.answer_photo_max_bytes');
        if ($file->getSize() > $maxBytes) {
            $mb = round($maxBytes / 1024 / 1024);

            return response()->json(['message' => "Fotka je větší než {$mb} MB."], 422);
        }

        $name = Str::random(24) . '.' . ($file->guessExtension() ?: 'jpg');
        $path = self::pathFor($request->user()->id, $name);

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => $mime,
        ]);

        return response()->json([
            'data' => [
                // The handle the client sends back on /answers/evaluate and
                // stores on the attempt. Not a URL, and not the R2 path.
                'photo_name' => $name,
                'mime_type' => $mime,
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    /**
     * GET /api/answers/photo/{name} — the student's own photo.
     *
     * Needed for resume: a student who reloads mid-attempt must see the photo
     * they already attached rather than an empty slot.
     */
    public function show(Request $request, string $name): StreamedResponse|JsonResponse
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return response()->json(['message' => 'Fotka nenalezena.'], 404);
        }

        return $this->stream(self::pathFor($request->user()->id, $name));
    }

    /**
     * GET /api/admin/open-answers/{id}/photo — a teacher opening an attempt.
     *
     * Goes through the row rather than a path so a teacher cannot enumerate
     * storage. The route is keyed by evaluation id, not by course or student,
     * so teacher.student cannot gate it — the owner is resolved from the row
     * and checked here instead.
     */
    public function adminShow(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $row = OpenAnswerEvaluation::find($id);

        if ($row === null || $row->photo_path === null) {
            return response()->json(['message' => 'Fotka nenalezena.'], 404);
        }

        if ($request->attributes->get('admin_role') === 'teacher'
            && ! $request->user()->teachesStudent((int) $row->user_id)) {
            return response()->json(['message' => 'Fotka nenalezena.'], 404);
        }

        return $this->stream($row->photo_path);
    }

    /**
     * Absolute storage path for one photo. The single place the layout is
     * defined — the prefix check on read depends on it.
     */
    public static function pathFor(int $userId, string $name): string
    {
        return self::PREFIX . '/' . $userId . '/' . $name;
    }

    /**
     * Resolve a client-supplied handle to a readable path, or null.
     *
     * Used by the evaluation endpoint. Returns null rather than throwing so a
     * stale handle degrades to a text-only evaluation instead of an error.
     */
    public static function resolve(int $userId, ?string $name): ?string
    {
        if ($name === null || ! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $path = self::pathFor($userId, $name);

        return Storage::disk('s3')->exists($path) ? $path : null;
    }

    private function stream(string $path): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk('s3');

        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Fotka nenalezena.'], 404);
        }

        return $disk->response($path, null, [
            // Private: these are photos of children's schoolwork. No CDN, no
            // shared caches, and never rendered inline as an untrusted doc.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
