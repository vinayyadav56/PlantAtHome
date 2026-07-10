<?php

namespace App\Modules\Search\Application\Subscribers;

use App\Modules\Search\Application\SearchService;
use App\Shared\Events\IntegrationMessage;

/**
 * Keeps the search projection in sync off the outbox: reacts to
 * catalog.product_published / catalog.product_updated and (re)indexes that one
 * product — a partial reindex, never a full rebuild (Section 12). Idempotent by
 * construction (upsert by product_uuid); the relay's ledger guarantees
 * exactly-once anyway.
 */
final class IndexProductProjection
{
    public function __construct(private readonly SearchService $search)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        $productUuid = $message->payload['product_uuid'] ?? null;
        if ($productUuid) {
            $this->search->indexProduct($productUuid);
        }
    }
}
