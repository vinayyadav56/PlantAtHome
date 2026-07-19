<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * Emitted (through the outbox) when a campaign run is created and handed to the
 * queue. Analytics/other contexts can subscribe without Marketing knowing them.
 */
final class CampaignLaunched extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $campaignUuid,
        private readonly string $runUuid,
        private readonly string $trigger,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'marketing.campaign_launched';
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return [
            'campaign_uuid' => $this->campaignUuid,
            'run_uuid'      => $this->runUuid,
            'trigger'       => $this->trigger,
            'actor_uuid'    => $this->actorUuid,
        ];
    }
}
