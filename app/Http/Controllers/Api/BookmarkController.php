<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    /**
     * Get all bookmarks for the authenticated user.
     * Supports ?course_id= filter.
     *
     * GET /api/user/bookmarks
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bookmark::where('user_id', $request->user()->id);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        $bookmarks = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $bookmarks->map(fn ($b) => [
                'id' => $b->id,
                'course_id' => $b->course_id,
                'lesson_id' => $b->lesson_id,
                'block_id' => $b->block_id,
                'note' => $b->note,
                'created_at' => $b->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create or update a bookmark (upsert on unique combo).
     *
     * POST /api/user/bookmarks
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|string|max:255',
            'lesson_id' => 'nullable|string|max:255',
            'block_id' => 'required|string|max:255',
            'note' => 'nullable|string|max:500',
        ]);

        $bookmark = Bookmark::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'course_id' => $validated['course_id'],
                'lesson_id' => $validated['lesson_id'] ?? '',
                'block_id' => $validated['block_id'],
            ],
            [
                'note' => $validated['note'] ?? null,
            ]
        );

        return response()->json([
            'id' => $bookmark->id,
            'status' => $bookmark->wasRecentlyCreated ? 'created' : 'updated',
        ], $bookmark->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Bulk sync bookmarks (for restoring on a new device).
     *
     * POST /api/user/bookmarks/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bookmarks' => 'required|array',
            'bookmarks.*.course_id' => 'required|string|max:255',
            'bookmarks.*.lesson_id' => 'nullable|string|max:255',
            'bookmarks.*.block_id' => 'required|string|max:255',
            'bookmarks.*.note' => 'nullable|string|max:500',
        ]);

        $userId = $request->user()->id;
        $results = [];

        foreach ($validated['bookmarks'] as $entry) {
            $bookmark = Bookmark::updateOrCreate(
                [
                    'user_id' => $userId,
                    'course_id' => $entry['course_id'],
                    'lesson_id' => $entry['lesson_id'] ?? '',
                    'block_id' => $entry['block_id'],
                ],
                [
                    'note' => $entry['note'] ?? null,
                ]
            );

            $results[] = [
                'id' => $bookmark->id,
                'course_id' => $bookmark->course_id,
                'lesson_id' => $bookmark->lesson_id,
                'block_id' => $bookmark->block_id,
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * Delete a bookmark by server ID.
     *
     * DELETE /api/user/bookmarks/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $bookmark = Bookmark::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $bookmark->delete();

        return response()->json([
            'message' => 'Bookmark deleted',
        ]);
    }

    /**
     * Delete a bookmark by composite key (for offline-created bookmarks without server ID).
     *
     * POST /api/user/bookmarks/delete
     */
    public function destroyByKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|string|max:255',
            'lesson_id' => 'nullable|string|max:255',
            'block_id' => 'required|string|max:255',
        ]);

        $query = Bookmark::where('user_id', $request->user()->id)
            ->where('course_id', $validated['course_id'])
            ->where('block_id', $validated['block_id']);

        if (!empty($validated['lesson_id'])) {
            $query->where('lesson_id', $validated['lesson_id']);
        }

        $deleted = $query->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Bookmark not found'], 404);
        }

        return response()->json([
            'message' => 'Bookmark deleted',
        ]);
    }
}
