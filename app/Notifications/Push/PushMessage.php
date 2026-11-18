<?php

namespace App\Notifications\Push;

use App\Models\UserNotification;

/**
 * What a device shows plus the data the app needs to act on a tap:
 * notification_id (to report events), deep_link and type_key. FCM data
 * values must be strings.
 */
final class PushMessage
{
    /** @param  array<string, string>  $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    public static function fromNotification(UserNotification $notification): self
    {
        return new self(
            title: (string) ($notification->push_title ?? $notification->title),
            body: (string) ($notification->push_body ?? $notification->body),
            data: [
                'notification_id' => (string) $notification->uuid,
                'deep_link' => (string) ($notification->deep_link ?? ''),
                'type_key' => (string) $notification->type_key,
            ],
        );
    }
}
