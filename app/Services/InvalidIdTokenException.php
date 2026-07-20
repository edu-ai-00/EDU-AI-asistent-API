<?php

namespace App\Services;

/**
 * Thrown when a Google id_token fails signature or claim validation.
 */
class InvalidIdTokenException extends \RuntimeException
{
}
