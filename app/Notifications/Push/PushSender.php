<?php

namespace App\Notifications\Push;

use App\Models\PushDevice;

/**
 * Delivers one push message to one device (spec §6.3). Bound in
 * AppServiceProvider: FcmPushSender when FCM credentials are configured,
 * NullPushSender otherwise.
 */
interface PushSender
{
    /** False → push is "unavailable": feed only, decisions say push_unavailable. */
    public function isConfigured(): bool;

    public function send(PushDevice $device, PushMessage $message): PushResult;
}
