<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Support\SessionTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodeController extends Controller
{
    /**
     * Resolve a 6-character alphanumeric code.
     * Checks student login codes first, then course PINs.
     *
     * POST /api/resolve-code
     */
    public function resolve(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6|alpha_num',
            'shared_device' => 'sometimes|boolean',
        ]);

        $code = strtoupper(trim($request->code));

        // 1. Check if code matches a student login code
        $user = User::where('login_code', $code)->first();

        if ($user) {
            // SPEC §5.4: reject login for soft-merged users (PIN/login-code path).
            // Source PINs are intentionally burned at merge — finding a merged
            // user here means the PIN was reused or the code is stale.
            if ($user->merged_into_user_id !== null) {
                return response()->json([
                    'message' => 'Profil byl sloučen do jiného účtu.',
                    'merged_into' => $user->merged_into_user_id,
                ], 410);
            }

            $issued = SessionTokens::issueLogin($user, $request, 'student-code-login');

            return response()->json([
                'type' => 'user',
                'token' => $issued['plainTextToken'],
                'expires_at' => $issued['expiresAt']?->toIso8601String(),
                'shared_device' => $issued['sharedDevice'],
                'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
        }

        // 2. Check if code matches a course PIN (alphanumeric, 6 chars)
        $course = Course::where('pin', $code)
            ->where('status', 'published')
            ->first();

        if ($course) {
            return response()->json([
                'type' => 'course',
                'course' => [
                    'id' => $course->id,
                    'course_id' => $course->course_id,
                    'name' => $course->name,
                    'version' => $course->version,
                    'status' => $course->status,
                    'language' => $course->language,
                    'description' => $course->description,
                    'author' => $course->author,
                    'emoji' => $course->emoji,
                    'lesson_count' => $course->lesson_count,
                    'estimated_minutes' => $course->estimated_minutes,
                    'file_size' => $course->file_size,
                    'data' => $course->data,
                    'created_at' => $course->created_at->toISOString(),
                    'updated_at' => $course->updated_at->toISOString(),
                ],
            ]);
        }

        // 3. Nothing found
        return response()->json(['message' => 'Code not found'], 404);
    }
}
