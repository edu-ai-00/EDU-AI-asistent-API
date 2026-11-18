<?php

namespace App\Notifications\Push;

use App\Models\PushDevice;

/**
 * Push without credentials: reports itself unconfigured, so the service
 * never queues a push and logs push_unavailable instead. send() is only
 * reachable if a job was queued while credentials still existed.
 */
class NullPushSender implements PushSender
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(PushDevice $device, PushMessage $message): PushResult
    {
        return PushResult::failed('push_unavailable');
    }
}
