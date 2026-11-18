<?php

namespace App\Support;

use App\Models\PushDevice;
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
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon, tokenId: int}
     */
    public static function issueLogin(User $user, Request $request, string $name = 'auth_token'): array
    {
        $shared = self::extractSharedFlag($request);
        self::recordRegistrationDevice($user, $request);
        return self::issue($user, $name, $shared, $shared ? now() : null);
    }

    /**
     * Remember the device of the account's first sign-in to the app
     * (BR-CMA88Y): the registration for a pupil who signs up, the first code
     * login for one a teacher provisioned. The admin student detail shows it
     * even when the pupil has done nothing since — work sessions only exist
     * once they work in a lesson or quiz.
     *
     * The app sends the device as X-Device-Platform / X-Device-Type, the same
     * values its work-time heartbeats carry. Only an account still waiting
     * for its onboarding is stamped, and only once: an established account's
     * next login is not its registration. Older app builds send nothing and
     * record nothing.
     */
    private static function recordRegistrationDevice(User $user, Request $request): void
    {
        if ($user->registration_device_at !== null || $user->onboarded_at !== null) {
            return;
        }

        $platform = self::deviceHeader($request, 'X-Device-Platform');
        $type = self::deviceHeader($request, 'X-Device-Type');
        if ($platform === null && $type === null) {
            return;
        }

        $user->forceFill([
            'registration_platform' => $platform,
            'registration_device_type' => $type,
            'registration_device_at' => now(),
        ])->save();
    }

    /**
     * A device header's value; null when absent or too long for the column.
     * Free-form otherwise, like the heartbeat fields: a value this server does
     * not know yet must not fail the sign-in.
     */
    private static function deviceHeader(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name, ''));

        return $value === '' || strlen($value) > 16 ? null : $value;
    }

    /**
     * Re-issue a token preserving the shared-device flag and session anchor
     * from the previous token. Used by /api/auth/refresh.
     *
     * $replacesTokenId is the token being rotated out: push devices it
     * registered move to the new token (NOTIF-3). A device belongs to a
     * session and is deleted with its token, so without this every rotation
     * would silently unregister the phone — every 30 minutes on a shared
     * device.
     *
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon, tokenId: int}
     */
    public static function reissue(
        User $user,
        bool $shared,
        ?Carbon $sessionStartedAt,
        string $name = 'auth_token',
        ?int $replacesTokenId = null,
    ): array {
        $issued = self::issue($user, $name, $shared, $sessionStartedAt);

        if ($replacesTokenId !== null) {
            PushDevice::repointToken($replacesTokenId, $issued['tokenId']);
        }

        return $issued;
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
     * @return array{plainTextToken: string, expiresAt: ?Carbon, sharedDevice: bool, sessionStartedAt: ?Carbon, tokenId: int}
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
            'tokenId' => (int) $token->accessToken->getKey(),
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
