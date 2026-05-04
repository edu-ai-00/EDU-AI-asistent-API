<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues Sanctum tokens annotated with shared-device session metadata.
 *
 * shared_device sessions get a 30-minute access TTL and an 8-hour hard cap
 * anchored at session_started_at; persistent sessions get a 30-day TTL.
 * Both flags are written to personal_access_tokens so /api/auth/refresh
 * can preserve them across rotations.
 */
class SessionTokens
{
    public const SHARED_TTL_MINUTES = 30;
    public const PERSISTENT_TTL_DAYS = 30;
    public const SHARED_HARD_CAP_HOURS = 8;

    /**
     * Issue a fresh login token. Initialises session_started_at = now().
     *
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon}
     */
    public static function issueLogin(User $user, Request $request, string $name = 'auth_token'): array
    {
        $shared = self::extractSharedFlag($request);
        return self::issue($user, $name, $shared, $shared ? now() : null);
    }

    /**
     * Re-issue a token preserving the shared-device flag and session anchor
     * from the previous token. Used by /api/auth/refresh.
     *
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon}
     */
    public static function reissue(User $user, bool $shared, ?Carbon $sessionStartedAt, string $name = 'auth_token'): array
    {
        return self::issue($user, $name, $shared, $sessionStartedAt);
    }

    /**
     * Read shared_device flag from request body. Default false (persistent)
     * for backward compatibility with clients that don't yet send the param.
     */
    public static function extractSharedFlag(Request $request): bool
    {
        return $request->boolean('shared_device', false);
    }

    /**
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon}
     */
    private static function issue(User $user, string $name, bool $shared, ?Carbon $sessionStartedAt): array
    {
        $expiresAt = $shared
            ? now()->addMinutes(self::SHARED_TTL_MINUTES)
            : now()->addDays(self::PERSISTENT_TTL_DAYS);

        /** @var NewAccessToken $token */
        $token = $user->createToken($name, ['*'], $expiresAt);

        $token->accessToken->forceFill([
            'shared_device' => $shared,
            'session_started_at' => $sessionStartedAt,
        ])->save();

        return [
            'plainTextToken' => $token->plainTextToken,
            'expiresAt' => $expiresAt,
            'sharedDevice' => $shared,
            'sessionStartedAt' => $sessionStartedAt,
        ];
    }

    /**
     * Returns true if the given session anchor is past the shared-device
     * hard cap (8 hours).
     */
    public static function exceedsHardCap(?Carbon $sessionStartedAt): bool
    {
        if (!$sessionStartedAt) {
            return false;
        }
        return $sessionStartedAt->diffInHours(now()) >= self::SHARED_HARD_CAP_HOURS;
    }
}
