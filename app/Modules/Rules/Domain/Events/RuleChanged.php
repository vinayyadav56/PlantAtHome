<?php

namespace App\Modules\Rules\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A rule was created/updated/toggled. Since rules feed the Configuration
 * resolution pipeline (visibility/compatibility) and Pricing, this invalidates
 * the affected read caches and lets Search reindex.
 */
final class RuleChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $ruleUuid,
        private readonly string $scope,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'rules.changed';
    }

    public function payload(): array
    {
        return ['rule_uuid' => $this->ruleUuid, 'scope' => $this->scope];
    }
}
