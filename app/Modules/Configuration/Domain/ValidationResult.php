<?php

namespace App\Modules\Configuration\Domain;

/**
 * The outcome of validating a customer's option selection. Collects ALL
 * violations (not just the first) so the UI can show them together (Section 5).
 * Each violation is { group, code, message }.
 */
final class ValidationResult
{
    /** @var array<int,array{group:?string,code:string,message:string}> */
    private array $violations = [];

    public function addViolation(string $code, string $message, ?string $group = null): void
    {
        $this->violations[] = ['group' => $group, 'code' => $code, 'message' => $message];
    }

    public function passes(): bool
    {
        return $this->violations === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /** @return array<int,array{group:?string,code:string,message:string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function toArray(): array
    {
        return [
            'valid'      => $this->passes(),
            'violations' => $this->violations,
        ];
    }
}
