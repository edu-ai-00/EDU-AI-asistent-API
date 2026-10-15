<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mentor;
use App\Models\UserCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mentors a student can start a chat with.
 *
 * The app still ships its own list of the built-in mentors; this endpoint is
 * what lets an admin-created mentor show up without an app release, and it is
 * the only way to reach course-bound (hidden) mentors.
 */
class MentorController extends Controller
{
    /**
     * GET /api/chat/mentors            → mentors offered in the new-chat list
     * GET /api/chat/mentors?course_id= → mentors of that course, hidden ones included
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'sometimes|integer',
        ]);

        $query = Mentor::query()->active();

        if (isset($validated['course_id'])) {
            $courseId = (int) $validated['course_id'];

            $enrolled = UserCourse::where('user_id', $request->user()->id)
                ->where('course_id', $courseId)
                ->exists();

            if (! $enrolled) {
                return response()->json(['message' => 'Nejsi zapsán v tomto kurzu'], 403);
            }

            $query->where('course_id', $courseId);
        } else {
            // Hidden mentors stay out of the general list — they are reachable
            // only through their course.
            $query->where('visibility', Mentor::VISIBILITY_PUBLIC);
        }

        $mentors = $query->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $mentors->map(fn (Mentor $m) => [
                'slug' => $m->slug,
                'name' => $m->name,
                'subtitle' => $m->subtitle,
                'description' => $m->description,
                'emoji' => $m->emoji,
                'avatar_url' => $m->avatar_url,
                // Which kind of mentor this is, so the client can label it
                // ("AI" vs. "knihovna") without knowing our slugs.
                'engine' => $m->engine,
                'visibility' => $m->visibility,
                'course_id' => $m->course_id,
            ]),
        ]);
    }
}
