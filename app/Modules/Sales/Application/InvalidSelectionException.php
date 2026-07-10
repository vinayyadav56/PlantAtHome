<?php

namespace App\Modules\Sales\Application;

use App\Shared\Application\DomainActionException;

/**
 * A cart/checkout line whose configuration selection failed validation. Carries
 * ALL violations so the client can show them together (surfaced in the error
 * response's data by ApiExceptionRenderer).
 */
final class InvalidSelectionException extends DomainActionException
{
    /** @param array<int,array{group:?string,code:string,message:string}> $violations */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('The selected configuration is not valid.', 'INVALID_SELECTION', 422, 'selection');
    }

    /** @return array<int,array{group:?string,code:string,message:string}> */
    public function violations(): array
    {
        return $this->violations;
    }
}
