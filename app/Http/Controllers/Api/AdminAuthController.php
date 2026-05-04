<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminAuthController extends Controller
{
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
    public function verify(Request $request): JsonResponse
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

        // Mark code as used
        $verificationCode->markAsVerified();

        // Ensure email is verified
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        // Create Sanctum token with admin ability. Admin tokens override
        // the global sanctum expiration with a shorter window because
        // admin compromise is high blast-radius — clients should refresh
        // via POST /api/admin/auth/refresh.
        $expiresAt = now()->addMinutes(
            (int) config('sanctum.admin_expiration_minutes', 60 * 8)
        );
        $token = $user->createToken('admin-token', ['admin'], $expiresAt)
            ->plainTextToken;

        return response()->json([
            'token' => $token,
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
     * Issue a fresh admin token and revoke the one used to make this
     * request. Clients should call this when a request returns 401 due
     * to token expiry; if the current token is already expired, sanctum
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
            (int) config('sanctum.admin_expiration_minutes', 60 * 8)
        );

        $current->delete();
        $token = $user->createToken($name, $abilities, $expiresAt)
            ->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
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
