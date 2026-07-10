<?php

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery filed a proposal for a new product. Notifications alert reviewers;
 * Analytics tracks vendor contribution. Catalog stays ignorant of who reacts.
 */
final class ProductProposalFiled extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $proposalUuid,
        private readonly string $nurseryId,
        private readonly ?string $suggestedName,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'catalog.product_proposal_filed';
    }

    public function payload(): array
    {
        return [
            'proposal_uuid'  => $this->proposalUuid,
            'nursery_id'     => $this->nurseryId,
            'suggested_name' => $this->suggestedName,
        ];
    }
}
