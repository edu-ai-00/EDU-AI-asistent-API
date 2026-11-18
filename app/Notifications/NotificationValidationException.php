<?php

namespace App\Notifications;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A send request that cannot be processed at all — unknown type, no
 * recipients, bad channels or payload, invalid source (spec §2.1).
 *
 * Problems with individual recipients never throw this; they are logged
 * decisions. Rendered as a Laravel-shaped 422 when it escapes a controller.
 */
class NotificationValidationException extends \RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages
     * @param  string  $reason  machine-readable: unknown_type | no_recipients |
     *                          invalid_channels | no_channels | invalid_payload |
     *                          invalid_source
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, list<string>>  $errors */
    public static function make(string $reason, string $field, string $message, array $errors = []): self
    {
        return new self($message, $errors ?: [$field => [$message]], $reason);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], 422);
    }
}
