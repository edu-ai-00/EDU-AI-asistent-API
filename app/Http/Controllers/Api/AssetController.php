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
     * List assets for a course.
     *
     * GET /api/admin/assets?course_id=biologie-101
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|string',
        ]);

        $courseId = $request->query('course_id');

        $assets = Asset::where('course_id', $courseId)
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
            'course_id' => 'required|string',
            'file' => 'required|file|mimetypes:' . implode(',', self::ALLOWED_MIMES),
        ]);

        $courseId = $request->input('course_id');
        $file = $request->file('file');
        $filename = $file->getClientOriginalName();
        $path = "assets/{$courseId}/{$filename}";

        // If file already exists, append timestamp before extension
        if (Storage::disk('s3')->exists($path)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = "{$name}-" . time() . ".{$ext}";
            $path = "assets/{$courseId}/{$filename}";
        }

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => $file->getMimeType(),
        ]);

        $publicUrl = rtrim(config('filesystems.disks.s3.url'), '/') . '/' . $path;

        $asset = Asset::create([
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
