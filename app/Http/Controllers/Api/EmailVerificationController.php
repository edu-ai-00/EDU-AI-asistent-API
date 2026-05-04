<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\VerificationCode;
use App\Support\SessionTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    /**
     * Check if an email already exists in the system.
     *
     * POST /api/email/check
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'exists' => $exists,
        ]);
    }

    /**
     * Send a verification code to the email.
     *
     * POST /api/email/send-code
     */
    public function sendCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $request->email;

        // Rate limiting: max 20 codes per email per hour (increased for testing)
        $recentCount = VerificationCode::countRecentCodes($email, 60);
        if ($recentCount >= 20) {
            return response()->json([
                'sent' => false,
                'error' => 'rate_limited',
                'message' => 'Too many verification codes requested. Please wait before trying again.',
            ], 429);
        }

        // Generate new verification code (10 minutes expiry)
        $verificationCode = VerificationCode::generateFor($email, 10);

        // Send email
        try {
            Mail::to($email)->send(new VerificationCodeMail(
                code: $verificationCode->code,
                expiresInMinutes: 10,
            ));

            return response()->json([
                'sent' => true,
                'expires_in' => 600, // 10 minutes in seconds
            ]);
        } catch (\Exception $e) {
            // Log the error but don't expose details
            report($e);

            return response()->json([
                'sent' => false,
                'error' => 'send_failed',
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify the code sent to the email.
     *
     * POST /api/email/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'shared_device' => 'sometimes|boolean',
        ]);

        $email = $request->email;
        $code = $request->code;

        // Find valid code
        $verificationCode = VerificationCode::findValidCode($email, $code);

        if (!$verificationCode) {
            // Check if code exists but is expired
            $expiredCode = VerificationCode::where('email', $email)
                ->where('code', $code)
                ->whereNull('verified_at')
                ->where('expires_at', '<=', now())
                ->exists();

            if ($expiredCode) {
                return response()->json([
                    'valid' => false,
                    'error' => 'code_expired',
                    'message' => 'Verification code has expired. Please request a new one.',
                ], 410);
            }

            return response()->json([
                'valid' => false,
                'error' => 'invalid_code',
                'message' => 'Invalid verification code.',
            ], 400);
        }

        // Mark as verified
        $verificationCode->markAsVerified();

        // Check if user exists and return token if so (case-insensitive)
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($user) {
            $user->email_verified_at = now();
            $user->save();

            // Generate token for existing user
            $issued = SessionTokens::issueLogin($user, $request, 'auth-token');

            return response()->json([
                'valid' => true,
                'message' => 'Email verified successfully.',
                'token' => $issued['plainTextToken'],
                'expires_at' => $issued['expiresAt']?->toIso8601String(),
                'shared_device' => $issued['sharedDevice'],
                'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
                'user_exists' => true,
            ]);
        }

        // New user - no token yet
        return response()->json([
            'valid' => true,
            'message' => 'Email verified successfully.',
            'user_exists' => false,
        ]);
    }
}
