<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    private const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/svg+xml',
        'audio/mpeg',
        'audio/wav',
        'video/mp4',
        'video/quicktime',
    ];

    /**
     * Folders that hold assets not belonging to any course.
     */
    private const ALLOWED_SCOPES = [
        'news',
    ];

    /**
     * List assets for a course, or for a course-less scope.
     *
     * GET /api/admin/assets?course_id=biologie-101
     * GET /api/admin/assets?scope=news
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required_without:scope|nullable|string',
            'scope' => 'required_without:course_id|nullable|in:' . implode(',', self::ALLOWED_SCOPES),
        ]);

        $scope = $request->query('scope');

        $assets = Asset::query()
            ->when($scope, fn ($query) => $query->where('scope', $scope))
            ->when(! $scope, fn ($query) => $query->where('course_id', $request->query('course_id'))->whereNull('scope'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $assets->map(fn (Asset $asset) => $this->formatAsset($asset)),
            'meta' => [
                'total' => $assets->count(),
                'total_size' => $assets->sum('size'),
            ],
        ]);
    }

    /**
     * Upload an asset.
     *
     * POST /api/admin/assets/upload
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required_without:scope|nullable|string',
            'scope' => 'required_without:course_id|nullable|in:' . implode(',', self::ALLOWED_SCOPES),
            'file' => 'required|file|mimetypes:' . implode(',', self::ALLOWED_MIMES),
        ]);

        $scope = $request->input('scope');
        $courseId = $scope ? null : $request->input('course_id');
        $folder = $scope ?: $courseId;
        $file = $request->file('file');
        $filename = $file->getClientOriginalName();
        $path = "assets/{$folder}/{$filename}";

        // If file already exists, append timestamp before extension
        if (Storage::disk('s3')->exists($path)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = "{$name}-" . time() . ".{$ext}";
            $path = "assets/{$folder}/{$filename}";
        }

        // BR-TBN2T6: a file deleted and uploaded again under the same name
        // lands on the same key, and lessons keep pointing at its URL. Without
        // Cache-Control, browsers cached the old bytes heuristically for days,
        // so the replacement never showed. no-cache makes every view revalidate
        // against the ETag (a cheap 304 when nothing changed).
        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => $file->getMimeType(),
            'CacheControl' => 'no-cache',
        ]);

        $publicUrl = rtrim(config('filesystems.disks.s3.url'), '/') . '/' . $path;

        $asset = Asset::create([
            'scope' => $scope,
            'course_id' => $courseId,
            'filename' => $filename,
            'path' => $path,
            'url' => $publicUrl,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        return response()->json([
            'data' => $this->formatAsset($asset),
        ], 201);
    }

    /**
     * Delete an asset.
     *
     * DELETE /api/admin/assets/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        Storage::disk('s3')->delete($asset->path);

        $asset->delete();

        return response()->json([
            'data' => (object) [],
            'message' => 'Asset deleted',
        ]);
    }

    private function formatAsset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'scope' => $asset->scope,
            'course_id' => $asset->course_id,
            'filename' => $asset->filename,
            'path' => $asset->path,
            'url' => $asset->url,
            'size' => $asset->size,
            'mime_type' => $asset->mime_type,
            'created_at' => $asset->created_at->toIso8601String(),
            'updated_at' => $asset->updated_at->toIso8601String(),
        ];
    }
}
