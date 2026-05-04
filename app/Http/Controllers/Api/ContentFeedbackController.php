<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentFeedbackController extends Controller
{
    /**
     * Store content feedback from the app.
     *
     * POST /api/content-feedback
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|string|max:255',
            'block_id' => 'required|string|max:255',
            'lesson_id' => 'nullable|string|max:255',
            'type' => 'required|in:question,like,dislike',
            'message' => 'required_if:type,question|nullable|string|max:500',
        ]);

        $feedback = ContentFeedback::create([
            'user_id' => $request->user()->id,
            ...$validated,
        ]);

        return response()->json([
            'id' => $feedback->id,
            'status' => 'received',
        ], 201);
    }

    /**
     * List all feedback (admin).
     * Supports ?course_id= and ?type= filters.
     *
     * GET /api/admin/content-feedback
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContentFeedback::with('user:id,name,email');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $feedback = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $feedback->map(fn ($f) => [
                'id' => $f->id,
                'user' => $f->user ? [
                    'id' => $f->user->id,
                    'name' => $f->user->name,
                    'email' => $f->user->email,
                ] : null,
                'course_id' => $f->course_id,
                'block_id' => $f->block_id,
                'lesson_id' => $f->lesson_id,
                'type' => $f->type,
                'message' => $f->message,
                'created_at' => $f->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $feedback->count(),
            ],
        ]);
    }

    /**
     * Get a single feedback item (admin).
     *
     * GET /api/admin/content-feedback/{id}
     */
    public function show(int $id): JsonResponse
    {
        $feedback = ContentFeedback::with('user:id,name,email')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $feedback->id,
                'user' => $feedback->user ? [
                    'id' => $feedback->user->id,
                    'name' => $feedback->user->name,
                    'email' => $feedback->user->email,
                ] : null,
                'course_id' => $feedback->course_id,
                'block_id' => $feedback->block_id,
                'lesson_id' => $feedback->lesson_id,
                'type' => $feedback->type,
                'message' => $feedback->message,
                'created_at' => $feedback->created_at->toIso8601String(),
            ],
        ]);
    }
}
