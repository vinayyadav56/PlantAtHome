<?php

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for Identity authentication/authorization failures. Carries a stable
 * machine `code` and the HTTP status the API layer should surface — so the
 * controller can turn any AuthException into the standard error envelope
 * without a growing switch statement.
 */
abstract class AuthException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'AUTH_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    abstract public function httpStatus(): int;
}
