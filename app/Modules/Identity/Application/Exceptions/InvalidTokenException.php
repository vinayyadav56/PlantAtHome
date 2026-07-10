<?php

namespace App\Modules\Identity\Application\Exceptions;

use Throwable;

/** A missing/expired/forged access token or a bad refresh token. */
final class InvalidTokenException extends AuthException
{
    public function __construct(string $message = 'Authentication token is invalid.', string $code = 'TOKEN_INVALID', ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
