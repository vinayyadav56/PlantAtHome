<?php

namespace App\Modules\Identity\Application\Exceptions;

use Throwable;

/** Wrong email/password, or a disabled account. Deliberately vague message. */
final class InvalidCredentialsException extends AuthException
{
    public function __construct(string $message = 'The provided credentials are incorrect.', ?Throwable $previous = null)
    {
        parent::__construct($message, 'INVALID_CREDENTIALS', $previous);
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
