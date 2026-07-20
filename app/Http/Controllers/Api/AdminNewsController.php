<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNewsController extends Controller
{
    /**
     * List all news (including drafts), newest first.
     *
     * GET /api/admin/news
     */
    public function index(): JsonResponse
    {
        $news = News::orderByDesc('created_at')->get();

        return response()->json([
            'data' => $news->map(fn ($item) => $this->formatNews($item)),
        ]);
    }

    /**
     * Create a news item.
     *
     * POST /api/admin/news
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'perex' => 'required|string|max:500',
            'body' => 'required|string',
            'publish' => 'sometimes|boolean',
        ]);

        $news = News::create([
            'title' => $validated['title'],
            'perex' => $validated['perex'],
            'body' => $validated['body'],
            'created_by' => $request->user()?->id,
            'published_at' => ! empty($validated['publish']) ? now() : null,
        ]);

        return response()->json([
            'data' => $this->formatNews($news),
        ], 201);
    }

    /**
     * Show a single news item (including drafts).
     *
     * GET /api/admin/news/{id}
     */
    public function show(int $id): JsonResponse
    {
        $news = News::findOrFail($id);

        return response()->json([
            'data' => $this->formatNews($news),
        ]);
    }

    /**
     * Update a news item.
     *
     * PUT /api/admin/news/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $news = News::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'perex' => 'sometimes|string|max:500',
            'body' => 'sometimes|string',
            'publish' => 'sometimes|boolean',
        ]);

        // Handle publish toggle: true keeps existing publish time (or sets now),
        // false reverts the item to a draft.
        if ($request->has('publish')) {
            $news->published_at = $request->boolean('publish')
                ? ($news->published_at ?? now())
                : null;
        }

        foreach (['title', 'perex', 'body'] as $field) {
            if (array_key_exists($field, $validated)) {
                $news->{$field} = $validated[$field];
            }
        }

        $news->save();

        return response()->json([
            'data' => $this->formatNews($news),
        ]);
    }

    /**
     * Delete a news item.
     *
     * DELETE /api/admin/news/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $news = News::findOrFail($id);
        $news->delete();

        return response()->json([
            'data' => true,
        ]);
    }

    /**
     * Format a news item for admin API responses.
     */
    private function formatNews(News $news): array
    {
        return [
            'id' => $news->id,
            'title' => $news->title,
            'perex' => $news->perex,
            'body' => $news->body,
            'published_at' => $news->published_at?->toIso8601String(),
            'is_published' => $news->published_at !== null && $news->published_at <= now(),
            'created_by' => $news->created_by,
            'created_at' => $news->created_at->toIso8601String(),
        ];
    }
}
