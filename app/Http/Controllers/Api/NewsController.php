<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsUserRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    /**
     * List published news for the authenticated user, newest first.
     *
     * GET /api/news
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $news = News::published()
            ->orderByDesc('published_at')
            ->get();

        // Load the set of news ids this user has already read (single query).
        $readIds = NewsUserRead::where('user_id', $userId)
            ->whereIn('news_id', $news->pluck('id'))
            ->pluck('news_id')
            ->all();
        $readIds = array_flip($readIds);

        $unreadCount = 0;

        $data = $news->map(function ($item) use ($readIds, &$unreadCount) {
            $isRead = isset($readIds[$item->id]);

            if (! $isRead) {
                $unreadCount++;
            }

            return [
                'id' => $item->id,
                'title' => $item->title,
                'perex' => $item->perex,
                'published_at' => $item->published_at?->toIso8601String(),
                'is_read' => $isRead,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Show a single published news item for the authenticated user.
     *
     * GET /api/news/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $news = News::published()->findOrFail($id);

        $isRead = NewsUserRead::where('news_id', $news->id)
            ->where('user_id', $userId)
            ->exists();

        return response()->json([
            'data' => [
                'id' => $news->id,
                'title' => $news->title,
                'perex' => $news->perex,
                'body' => $news->body,
                'published_at' => $news->published_at?->toIso8601String(),
                'is_read' => $isRead,
            ],
        ]);
    }

    /**
     * Mark a published news item as read for the authenticated user.
     *
     * POST /api/news/{id}/read
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $news = News::published()->findOrFail($id);

        // Idempotent: create the read record once, never duplicate it.
        NewsUserRead::firstOrCreate(
            ['news_id' => $news->id, 'user_id' => $userId],
            ['read_at' => now()],
        );

        return response()->json([
            'data' => [
                'unread_count' => $this->unreadCountForUser($userId),
            ],
        ]);
    }

    /**
     * Count published news the user has not yet read.
     */
    private function unreadCountForUser(int $userId): int
    {
        return News::published()
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->count();
    }
}
