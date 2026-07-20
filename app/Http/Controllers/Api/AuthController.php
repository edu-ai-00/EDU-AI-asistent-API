<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\UserMergeService;
use App\Support\SessionTokens;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $issued = SessionTokens::issueLogin($user, $request);

        return response()->json([
            'user' => $user,
            'token' => $issued['plainTextToken'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expiresAt']?->toIso8601String(),
            'shared_device' => $issued['sharedDevice'],
            'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
        ], 201);
    }

    /**
     * Register or sync a user after email verification.
     * No password required - email verification acts as authentication.
     *
     * POST /api/register-verified
     */
    public function registerVerified(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'name' => 'required|string|max:255',
            'avatar_index' => 'nullable|integer|min:0',
            'selected_subjects' => 'nullable|array',
        ]);

        $email = $validated['email'];

        // Check if email was verified (within last hour for security)
        $recentlyVerified = VerificationCode::where('email', $email)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subHour())
            ->exists();

        if (!$recentlyVerified) {
            return response()->json([
                'success' => false,
                'error' => 'email_not_verified',
                'message' => 'Email has not been verified or verification expired. Please verify your email first.',
            ], 403);
        }

        // Check if user already exists
        $user = User::where('email', $email)->first();
        $isNewUser = false;

        // SPEC §5.4: reject token issuance for soft-merged users via the
        // verified-email path. (Source rows have email nulled at merge time
        // so this is mostly defence-in-depth, but a future flow could leave
        // a merged email intact.)
        if ($user && $user->merged_into_user_id !== null) {
            return response()->json([
                'message' => 'Profil byl sloučen do jiného účtu.',
                'merged_into' => $user->merged_into_user_id,
            ], 410);
        }

        if ($user) {
            // Existing user - only update profile fields if non-empty values provided
            $updateData = [];
            if (!empty($validated['name'])) {
                $updateData['name'] = $validated['name'];
            }
            if (isset($validated['avatar_index'])) {
                $updateData['avatar_index'] = $validated['avatar_index'];
            }
            if (isset($validated['selected_subjects'])) {
                $updateData['selected_subjects'] = $validated['selected_subjects'];
            }

            if (!empty($updateData)) {
                $user->update($updateData);
                $user->refresh();
            }
        } else {
            // New user - create account
            $user = User::create([
                'name' => $validated['name'],
                'email' => $email,
                'email_verified_at' => now(),
                'avatar_index' => $validated['avatar_index'] ?? null,
                'selected_subjects' => $validated['selected_subjects'] ?? null,
            ]);
            $isNewUser = true;
        }

        // Create auth token
        $issued = SessionTokens::issueLogin($user, $request);

        return response()->json([
            'success' => true,
            'is_new_user' => $isNewUser,
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

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // SPEC §5.4: reject login for soft-merged users.
        if ($user->merged_into_user_id !== null) {
            return response()->json([
                'message' => 'Profil byl sloučen do jiného účtu.',
                'merged_into' => $user->merged_into_user_id,
            ], 410);
        }

        $issued = SessionTokens::issueLogin($user, $request);

        return response()->json([
            'user' => $user,
            'token' => $issued['plainTextToken'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expiresAt']?->toIso8601String(),
            'shared_device' => $issued['sharedDevice'],
            'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Issue a fresh token for the authenticated user and revoke the
     * one used to make this request. Clients should call this when a
     * request returns 401 due to token expiry; if the current token is
     * already expired, sanctum middleware will reject the call and the
     * client must re-authenticate from scratch.
     *
     * POST /api/auth/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $name = $current->name ?? 'auth_token';

        $shared = (bool) ($current->shared_device ?? false);
        $sessionStartRaw = $current->session_started_at ?? null;
        $sessionStart = $sessionStartRaw ? Carbon::parse($sessionStartRaw) : null;

        // Shared-device sessions cap at 8h regardless of activity. Reject
        // refresh past the cap so the client falls back to re-auth.
        if ($shared && SessionTokens::exceedsHardCap($sessionStart)) {
            $current->delete();
            return response()->json([
                'message' => 'Session expired',
                'reason' => 'shared_device_hard_cap',
            ], 401);
        }

        // Allow the client to change the session mode on refresh. The
        // shared-device banner's "this is my device" action converts a shared
        // session to a persistent one (BR-CAQQW2). When the param is absent,
        // preserve the current mode — automated token rotation must not alter
        // it. Entering shared mode re-anchors the 8h cap; leaving it drops the
        // anchor (persistent sessions are uncapped).
        if ($request->has('shared_device')) {
            $requestedShared = $request->boolean('shared_device');
            if ($requestedShared !== $shared) {
                $shared = $requestedShared;
                $sessionStart = $shared ? now() : null;
            }
        }

        $issued = SessionTokens::reissue($user, $shared, $sessionStart, $name);
        $current->delete();

        return response()->json([
            'token' => $issued['plainTextToken'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expiresAt']?->toIso8601String(),
            'shared_device' => $issued['sharedDevice'],
            'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Register a guest (anonymous) user.
     * If device_id matches an existing guest, reconnect to that account.
     *
     * POST /api/guest/register
     */
    public function registerGuest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'force_new' => 'sometimes|boolean',
        ]);

        $forceNew = !empty($validated['force_new']);
        $user = null;

        if (!$forceNew) {
            // Reconnect to the most recent guest on this device
            $user = User::where('device_id', $validated['device_id'])
                ->where('is_guest', true)
                ->latest()
                ->first();
        } else {
            // Detach device_id and revoke tokens from ALL previous guests on this device
            $oldGuests = User::where('device_id', $validated['device_id'])
                ->where('is_guest', true)
                ->get();

            foreach ($oldGuests as $oldGuest) {
                $oldGuest->tokens()->delete();
                $oldGuest->update(['device_id' => null]);
            }
        }

        if (!$user) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => null,
                'password' => null,
                'is_guest' => true,
                'device_id' => $validated['device_id'],
            ]);
        }

        $issued = SessionTokens::issueLogin($user, $request);

        return response()->json([
            'success' => true,
            'token' => $issued['plainTextToken'],
            'expires_at' => $issued['expiresAt']?->toIso8601String(),
            'shared_device' => $issued['sharedDevice'],
            'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_guest' => $user->is_guest,
                'device_id' => $user->device_id,
            ],
        ], $user->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Upgrade a guest account to a full account with a verified email.
     *
     * POST /api/guest/claim
     */
    public function claimGuestAccount(Request $request, UserMergeService $mergeService): JsonResponse
    {
        $user = $request->user();

        if (!$user->is_guest) {
            return response()->json([
                'success' => false,
                'error' => 'not_a_guest',
                'message' => 'This account is already a full account.',
            ], 422);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'name' => 'required|string|max:255',
            'avatar_index' => 'nullable|integer|min:0',
            'selected_subjects' => 'nullable|array',
        ]);

        $email = $validated['email'];

        // Check if email was recently verified
        $recentlyVerified = VerificationCode::where('email', $email)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subHour())
            ->exists();

        if (!$recentlyVerified) {
            return response()->json([
                'success' => false,
                'error' => 'email_not_verified',
                'message' => 'Email has not been verified or verification expired. Please verify your email first.',
            ], 403);
        }

        // If the verified email already belongs to another full account, fold
        // this guest into that account instead of rejecting. The recent
        // verification proves the caller controls the email, so its in-progress
        // courses/progress are merged into the existing profile rather than
        // orphaned on the guest row (BR-4FTCFH).
        $existing = User::where('email', $email)->where('id', '!=', $user->id)->first();
        if ($existing) {
            $merge = $mergeService->absorbGuest($user, $existing);

            if ($merge === null) {
                // Target cannot accept the merge (e.g. admin/teacher or already
                // merged) — fall back to the original conflict response.
                return response()->json([
                    'success' => false,
                    'error' => 'email_taken',
                    'message' => 'This email is already associated with another account.',
                ], 409);
            }

            $existing->refresh();
            $issued = SessionTokens::issueLogin($existing, $request);

            return response()->json([
                'success' => true,
                'is_new_user' => false,
                'merged' => true,
                'user' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'email' => $existing->email,
                    'avatar_index' => $existing->avatar_index,
                    'selected_subjects' => $existing->selected_subjects,
                    'email_verified_at' => $existing->email_verified_at,
                ],
                'token' => $issued['plainTextToken'],
                'token_type' => 'Bearer',
                'expires_at' => $issued['expiresAt']?->toIso8601String(),
                'shared_device' => $issued['sharedDevice'],
                'session_started_at' => $issued['sessionStartedAt']?->toIso8601String(),
            ]);
        }

        $user->update([
            'email' => $email,
            'name' => $validated['name'],
            'is_guest' => false,
            'email_verified_at' => now(),
            'avatar_index' => $validated['avatar_index'] ?? $user->avatar_index,
            'selected_subjects' => $validated['selected_subjects'] ?? $user->selected_subjects,
        ]);

        $user->refresh();

        $issued = SessionTokens::issueLogin($user, $request);

        return response()->json([
            'success' => true,
            'is_new_user' => false,
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
        ]);
    }

    /**
     * Update user profile.
     *
     * PUT /api/user/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'avatar_index' => 'sometimes|integer|min:0|max:10',
        ]);

        $user = $request->user();

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['avatar_index'])) {
            $user->avatar_index = $validated['avatar_index'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_index' => $user->avatar_index,
                'selected_subjects' => $user->selected_subjects,
            ],
        ]);
    }
}
