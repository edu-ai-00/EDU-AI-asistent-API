<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\Course;
use App\Models\User;
use App\Services\UserMergeService;
use App\Support\SessionTokens;
use App\Support\StudentCodes;
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
    public function resolve(Request $request, UserMergeService $mergeService): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6|alpha_num',
            'shared_device' => 'sometimes|boolean',
            'merge_guest' => 'sometimes|boolean',
        ]);

        $code = strtoupper(trim($request->code));

        // 1. Student login code — looked up ONLY by hash. Every account that
        //    has a code has a login_code_hash (backfilled by migration
        //    2026_08_13_000006); school accounts additionally keep the
        //    plaintext so a teacher can re-read it in admin, but plaintext is
        //    never the lookup key. One identity, one column: that is what
        //    stops a child's generated code and a school student's typed code
        //    from resolving to different accounts, including when Crockford
        //    normalisation makes ABCO12 and ABC012 the same code (BR-8FDF37).
        $user = User::where('login_code_hash', StudentCodes::hash($code))->first();

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

            // Before the guest merge below touches the account (BR-Q3ZJ6Q).
            $user->ensureCanSignIn();

            // A minor may only sign in while their guardian's consent stands.
            // Revocation locks the account out immediately; the data itself is
            // erased later by consent:purge-revoked.
            //
            // Deliberately "is the CURRENT consent confirmed", not "does ANY
            // confirmed consent exist for this guardian" — the latter was
            // bypassable: a guardian can hold more than one row that was
            // once confirmed (ConsentService::confirm() now supersedes a
            // prior CONFIRMED row on every fresh confirmation, and revoke()
            // revokes every CONFIRMED row for the guardian, but a row that
            // predates those fixes, or a future write path that skips the
            // service, could still leave two live). Revoking one of them
            // left the "any exists" query still satisfied by the other,
            // so a revoked guardian's children kept logging in (BR-8FDF37
            // C1). Consent::governingFor() picks the most recent row that
            // ever governed logins and this asks only about that one.
            if ($user->is_minor) {
                $governingConsent = Consent::governingFor((int) $user->guardian_id);

                if (! $governingConsent || ! $governingConsent->isConfirmed()) {
                    return response()->json([
                        'message' => 'Souhlas rodiče byl odvolán. Přihlášení není možné.',
                    ], 403);
                }
            }

            // If the caller is currently an anonymous guest (its bearer token
            // is still the guest's here — the client switches to the token
            // returned below), fold the guest's courses/progress into the
            // account being signed into, same as the email-verify path
            // (BR-4FTCFH). Skipped on shared devices: there the previous local
            // user is likely a different person and their data must NOT leak
            // into this student's account (BR-ZDXP7C). Also skipped for minors
            // outright — an adult guest's history must never land on a child's
            // pseudonymous account (BR-8FDF37).
            //
            // merge_guest lifts the shared-device opt-out: the app sends it
            // when the code was typed into the login screen the guest opened
            // from inside their own session, so the guest IS the person
            // signing in. shared_device alone can't tell — it is the app's
            // default, and gating on it dropped every such guest's progress
            // (BR-35W2HV).
            $mergeGuest = $request->boolean('merge_guest')
                || ! $request->boolean('shared_device');
            if ($mergeGuest && ! $user->is_minor) {
                $mergeService->absorbGuest($request->user('sanctum'), $user);
                $user->refresh();
            }

            $issued = SessionTokens::issueLogin($user, $request, 'student-code-login');

            return response()->json([
                'type' => 'user',
                // A student's very first entry into the app is a code login,
                // not a registration — the teacher created the account, the
                // student has never seen it. This path used to answer "no
                // onboarding needed" outright, so a student never got the
                // vibe step at all (BR-YXVMKY).
                'profile_setup_required' => ! $user->hasOnboarded(),
                'token' => $issued['plainTextToken'],
                'expires_at' => $issued['expiresAt']?->toIso8601String(),
                'shared_device' => $issued['sharedDevice'],
                'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
                'minor' => (bool) $user->is_minor,
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
