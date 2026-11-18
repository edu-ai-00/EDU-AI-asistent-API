<?php

namespace Tests\Feature\Notifications;

use App\Models\PushDevice;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushResult;
use App\Notifications\Push\PushSender;

/**
 * A configured push sender that records what it was asked to send and
 * answers with a scripted result (success unless told otherwise).
 */
class FakePushSender implements PushSender
{
    /** @var list<array{device: PushDevice, message: PushMessage}> */
    public array $sent = [];

    /** @var array<string, PushResult> token => result */
    public array $results = [];

    public ?PushResult $default = null;

    public function __construct(public bool $configured = true) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(PushDevice $device, PushMessage $message): PushResult
    {
        $this->sent[] = ['device' => $device, 'message' => $message];

        return $this->results[$device->token] ?? $this->default ?? PushResult::success();
    }
}
