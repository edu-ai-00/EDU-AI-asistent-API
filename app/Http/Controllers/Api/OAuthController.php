<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppleIdTokenVerifier;
use App\Services\GoogleIdTokenVerifier;
use App\Services\InvalidIdTokenException;
use App\Services\MicrosoftIdTokenVerifier;
use App\Support\SessionTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function __construct(
        private GoogleIdTokenVerifier $googleVerifier,
        private AppleIdTokenVerifier $appleVerifier,
        private MicrosoftIdTokenVerifier $microsoftVerifier,
    ) {
    }

    /**
     * Exchange a Google id_token for a Sanctum session token.
     *
     * The client (Flutter, all platforms) obtains an id_token via
     * google_sign_in and posts it here. We verify it against Google's keys,
     * then find-or-create the user by their verified email.
     *
     * POST /api/auth/google
     */
    public function google(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
            'shared_device' => 'sometimes|boolean',
        ]);

        try {
            $claims = $this->googleVerifier->verify($request->string('id_token'));
        } catch (InvalidIdTokenException) {
            return response()->json([
                'message' => 'Invalid Google credential.',
                'error' => 'invalid_id_token',
            ], 401);
        }

        $email = isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : null;
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $email || ! $emailVerified) {
            return response()->json([
                'message' => 'Google account has no verified email.',
                'error' => 'email_unverified',
            ], 422);
        }

        return $this->loginByEmail($email, trim((string) ($claims['name'] ?? '')), $request);
    }

    /**
     * Exchange an Apple "Sign in with Apple" identity token for a session.
     *
     * Apple returns the user's name only on the first authorization (in the
     * request body, not the token); the email lives in the id_token every
     * time. We match/create by that verified email.
     *
     * POST /api/auth/apple
     */
    public function apple(Request $request): JsonResponse
    {
        $request->validate([
            'identity_token' => 'required|string',
            'authorization_code' => 'sometimes|string',
            'user' => 'sometimes|array',
            'user.name' => 'sometimes|string|max:255',
            'shared_device' => 'sometimes|boolean',
        ]);

        try {
            $claims = $this->appleVerifier->verify($request->string('identity_token'));
        } catch (InvalidIdTokenException) {
            return response()->json([
                'message' => 'Invalid Apple credential.',
                'error' => 'invalid_id_token',
            ], 401);
        }

        $email = isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : null;
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $email || ! $emailVerified) {
            return response()->json([
                'message' => 'Apple account has no verified email.',
                'error' => 'email_unverified',
            ], 422);
        }

        // Name is only present on first sign-in, and only in the request body.
        $nameFallback = trim((string) $request->input('user.name', ''));

        return $this->loginByEmail($email, $nameFallback, $request);
    }

    /**
     * Exchange a Microsoft (Entra ID) id_token for a session.
     *
     * Microsoft tokens carry no email_verified claim — the signed token itself
     * proves the user owns the account. The email lives in `email` or, for
     * work/school accounts, `preferred_username` (the UPN).
     *
     * POST /api/auth/microsoft
     */
    public function microsoft(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
            'shared_device' => 'sometimes|boolean',
        ]);

        try {
            $claims = $this->microsoftVerifier->verify($request->string('id_token'));
        } catch (InvalidIdTokenException) {
            return response()->json([
                'message' => 'Invalid Microsoft credential.',
                'error' => 'invalid_id_token',
            ], 401);
        }

        $rawEmail = $claims['email'] ?? $claims['preferred_username'] ?? null;
        $email = $rawEmail ? strtolower(trim((string) $rawEmail)) : null;

        // Guard against a non-email UPN sneaking through as the identifier.
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'Microsoft account has no usable email.',
                'error' => 'email_unavailable',
            ], 422);
        }

        return $this->loginByEmail($email, trim((string) ($claims['name'] ?? '')), $request);
    }

    /**
     * Find or create the user for a verified email and issue a session token.
     * Shared by all social providers once they've validated their token.
     */
    private function loginByEmail(string $email, string $nameFallback, Request $request): JsonResponse
    {
        $user = User::where('email', $email)->first();

        // Refuse to log into an account that was folded into another one.
        if ($user && $user->merged_into_user_id !== null) {
            return response()->json([
                'message' => 'Profil byl sloučen do jiného účtu.',
                'merged_into' => $user->merged_into_user_id,
            ], 410);
        }

        $isNewUser = false;
        if (! $user) {
            $user = User::create([
                'name' => $nameFallback !== '' ? $nameFallback : $email,
                'email' => $email,
                'email_verified_at' => now(),
            ]);
            $isNewUser = true;
        } elseif ($user->email_verified_at === null) {
            // The provider has now proven ownership of a previously
            // unverified email.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $issued = SessionTokens::issueLogin($user, $request);

        // Route new users (or those who never picked subjects) into onboarding.
        $profileSetupRequired = $isNewUser || empty($user->selected_subjects);

        return response()->json([
            'is_new_user' => $isNewUser,
            'profile_setup_required' => $profileSetupRequired,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_index' => $user->avatar_index,
                'selected_subjects' => $user->selected_subjects,
                'email_verified_at' => $user->email_verified_at,
            ],
            'token' => $issued['plainTextToken'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expiresAt']?->toIso8601String(),
            'shared_device' => $issued['sharedDevice'],
            'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
        ], $isNewUser ? 201 : 200);
    }
}
