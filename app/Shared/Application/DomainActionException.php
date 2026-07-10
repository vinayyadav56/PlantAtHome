<?php

namespace App\Shared\Application;

use RuntimeException;
use Throwable;

/**
 * A use-case failure that maps to a clean 4xx (not a 500). Application services
 * throw these for expected error conditions — a missing referenced resource, a
 * conflicting state transition, invalid input the DB layer would otherwise
 * reject — so the API returns a stable machine code in the standard envelope
 * instead of an opaque SERVER_ERROR. Mapped centrally by ApiExceptionRenderer.
 */
class DomainActionException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'DOMAIN_ERROR',
        private readonly int $status = 422,
        private readonly ?string $field = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /** Referenced resource does not exist / bad input reference → 422. */
    public static function unprocessable(string $message, string $code, ?string $field = null): self
    {
        return new self($message, $code, 422, $field);
    }

    /** Resource not found → 404. */
    public static function notFound(string $message, string $code = 'NOT_FOUND'): self
    {
        return new self($message, $code, 404);
    }

    /** Illegal state transition / concurrent conflict → 409. */
    public static function conflict(string $message, string $code = 'CONFLICT'): self
    {
        return new self($message, $code, 409);
    }
}
