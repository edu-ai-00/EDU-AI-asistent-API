<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * FCM token registration (NOTIF-3).
 *
 * A device is tied to the session (Sanctum token) that registered it, so
 * logging out — or the session expiring or being pruned — removes it, and a
 * shared school tablet never pushes one pupil's notifications to the next.
 * Registering a token that already belongs to someone else moves it: one
 * physical device, one user.
 */
class PushDeviceController extends Controller
{
    /**
     * POST /api/push-devices {token, platform, app_version?}
     * 201 for a new device, 200 when a known token was updated or moved.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'required|string|in:' . implode(',', PushDevice::PLATFORMS),
            'app_version' => 'sometimes|nullable|string|max:32',
        ]);

        $attributes = [
            'user_id' => $request->user()->id,
            'platform' => $validated['platform'],
            'app_version' => $validated['app_version'] ?? null,
            'personal_access_token_id' => $this->currentTokenId($request),
            'last_seen_at' => now(),
            // Re-registering is the app saying the token works again.
            'disabled_at' => null,
            'disabled_reason' => null,
        ];

        $device = PushDevice::where('token', $validated['token'])->first();
        $created = false;

        if ($device === null) {
            try {
                $device = PushDevice::create(['token' => $validated['token']] + $attributes);
                $created = true;
            } catch (UniqueConstraintViolationException) {
                // The same token registered by a parallel request.
                $device = PushDevice::where('token', $validated['token'])->firstOrFail();
            }
        }

        if (!$created) {
            $device->forceFill($attributes)->save();
        }

        return response()->json([
            'data' => ['id' => $device->id, 'platform' => $device->platform],
        ], $created ? 201 : 200);
    }

    /**
     * DELETE /api/push-devices {token} — idempotent. Only the caller's own
     * device; someone else's token is left alone (and reported the same way).
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        PushDevice::where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['data' => true]);
    }

    /** The bearer token behind this request; null for a cookie session. */
    private function currentTokenId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
