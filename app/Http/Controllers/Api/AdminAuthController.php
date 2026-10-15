<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminAuthController extends Controller
{
    /**
     * How long a token rotated out by refresh() keeps working.
     */
    private const REFRESH_GRACE_SECONDS = 60;

    /**
     * Send a 6-digit verification code to an admin/teacher email.
     *
     * POST /api/admin/auth/send-code
     */
    public function sendCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));

        // Always return success to prevent email enumeration.
        // Only actually send if user exists with admin/teacher role.
        //
        // An expired invite (BR-XW6CWV) still gets its code, on purpose: the
        // only place the panel can say "your invite expired, ask for a new
        // one" is verify(), and without a code the person never gets that far.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $user->isAdminOrTeacher()) {
            // Rate limiting: max 5 codes per email per hour
            $recentCount = VerificationCode::countRecentCodes($email, 60);
            if ($recentCount < 5) {
                $verificationCode = VerificationCode::generateFor($email, 10);

                try {
                    Mail::to($email)->send(new VerificationCodeMail(
                        code: $verificationCode->code,
                        expiresInMinutes: 10,
                    ));
                } catch (\Exception $e) {
                    report($e);
                }
            }
        }

        return response()->json([
            'sent' => true,
            'expires_in' => 600,
        ]);
    }

    /**
     * Verify the 6-digit code, check role, return Sanctum token.
     *
     * POST /api/admin/auth/verify
     */
    public function verify(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $email = strtolower(trim($request->email));

        $verificationCode = VerificationCode::findValidCode($email, $request->code);

        if (!$verificationCode) {
            return response()->json([
                'error' => 'invalid_code',
                'message' => 'Invalid or expired verification code.',
            ], 401);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !$user->isAdminOrTeacher()) {
            return response()->json([
                'error' => 'access_denied',
                'message' => 'This account does not have admin access.',
            ], 403);
        }

        $user->ensureCanSignIn();

        // An invite nobody accepted in time (BR-XW6CWV). Checked after the
        // code, so only whoever holds the mailbox learns the account's state.
        if ($user->hasExpiredInvite()) {
            return response()->json([
                'error' => 'invite_expired',
                'message' => 'Platnost pozvánky vypršela. Požádejte administrátora, aby vám ji poslal znovu.',
            ], 403);
        }

        // Mark code as used
        $verificationCode->markAsVerified();

        // Ensure email is verified
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        // The first sign-in is what accepting the invite means: from here the
        // account is active and the invite can no longer expire.
        if ($user->invite_expires_at !== null) {
            $user->update(['invite_expires_at' => null]);
            // No authenticated actor yet at this point — the person accepting
            // is the target and the actor both, so they are named as such;
            // read off the request, the row had no actor at all (BR-6UD3NC).
            $audit->log('user.invite_accepted', $user, actor: $user);
        }

        // Create Sanctum token with admin ability. Admin tokens override
        // the global sanctum expiration with a shorter window because
        // admin compromise is high blast-radius — clients should refresh
        // via POST /api/admin/auth/refresh.
        $expiresAt = now()->addMinutes(
            (int) config('sanctum.admin_expiration_minutes', 60 * 48)
        );
        $token = $user->createToken('admin-token', ['admin'], $expiresAt)
            ->plainTextToken;

        return response()->json([
            'token' => $token,
            // The panel stores the token in a cookie; without this it had no
            // way to know when the token dies and kept a week-long
            // cookie around a session the API had already retired (BR-4U7XUW).
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Revoke the current token.
     *
     * POST /api/admin/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Issue a fresh admin token and retire the one used to make this
     * request. The admin panel calls this from its backend proxy while the
     * session is in use; if the current token is already expired, sanctum
     * middleware rejects the call and the client must re-login.
     *
     * POST /api/admin/auth/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $name = $current->name ?? 'admin-token';
        $abilities = $current->abilities ?? ['admin'];
        $expiresAt = now()->addMinutes(
            (int) config('sanctum.admin_expiration_minutes', 60 * 48)
        );

        // The old token is cut down to a short grace rather than deleted.
        // The panel's requests go out in parallel and each one that finds the
        // token due refreshes with it, so deleting it here failed every sibling
        // refresh but the first — and a failed refresh is a logout (BR-9SX9BC).
        $graceEnd = now()->addSeconds(self::REFRESH_GRACE_SECONDS);
        if ($current->expires_at === null || $current->expires_at->greaterThan($graceEnd)) {
            $current->forceFill(['expires_at' => $graceEnd])->save();
        }

        $token = $user->createToken($name, $abilities, $expiresAt)
            ->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Return the current authenticated admin/teacher user.
     *
     * GET /api/admin/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
