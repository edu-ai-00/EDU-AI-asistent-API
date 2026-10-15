<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A student code cannot be used to join a class right now (BR-3V2P22).
 *
 * Carries the HTTP status the API answers with; the message is the
 * client-facing Czech copy the app shows verbatim under the code input.
 *
 * Every throw is a 4xx answer to what the caller typed — unknown code,
 * already a member, a minor's account — so, like a validation error, it
 * is never reported: it would only page Nightwatch for the product
 * working as designed (BR-472CMD).
 */
class ClassroomJoinException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
