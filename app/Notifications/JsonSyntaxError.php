<?php

namespace App\Notifications;

/** Where a JSON document breaks: a Czech description plus the byte offset. */
final class JsonSyntaxError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $offset)
    {
        parent::__construct($message);
    }
}
