<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A login path tried to open a session for a deactivated account
 * (BR-Q3ZJ6Q). Thrown by User::ensureCanSignIn(); renders as 403 with the
 * Czech copy the client shows the person trying to sign in.
 */
class AccountDeactivatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tento účet byl deaktivován. Pro obnovení přístupu kontaktujte správce.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'account_deactivated',
            'message' => $this->getMessage(),
        ], 403);
    }
}
