<?php

namespace App\Notifications\Push;

/**
 * Outcome of one device send. Exactly one of ok / invalidToken / retryable
 * is true, or none of them for a permanent failure that is not the token's
 * fault (a malformed message, a misconfigured project).
 */
final class PushResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $invalidToken = false,
        public readonly bool $retryable = false,
        public readonly ?string $error = null,
    ) {}

    public static function success(): self
    {
        return new self(ok: true);
    }

    /** The token is dead — disable the device, never retry it. */
    public static function invalidToken(string $error): self
    {
        return new self(ok: false, invalidToken: true, error: $error);
    }

    /** Transient (timeout, 429, 5xx) — worth another attempt later. */
    public static function retryable(string $error): self
    {
        return new self(ok: false, retryable: true, error: $error);
    }

    public static function failed(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
