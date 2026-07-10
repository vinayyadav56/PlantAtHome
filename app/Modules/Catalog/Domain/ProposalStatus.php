<?php

namespace App\Modules\Catalog\Domain;

/** Lifecycle of a nursery's product proposal. */
final class ProposalStatus
{
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];
}
