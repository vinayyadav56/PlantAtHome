<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\ProductProposalFiled;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Domain\ProposalStatus;
use App\Modules\Catalog\Infrastructure\Models\ProductProposal;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product-proposal use-cases. A nursery FILES a proposal (never a product);
 * admin APPROVES it — which materializes a real (draft) master-catalog product
 * — or REJECTS it. This is the enforcement point of the "nurseries never create
 * product rows" ownership contract (Section 3).
 */
class ProposalService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly ProductService $products,
    ) {
    }

    public function file(string $nurseryId, ?string $proposedBy, array $payload): ProductProposal
    {
        return DB::transaction(function () use ($nurseryId, $proposedBy, $payload) {
            $proposal = ProductProposal::create([
                'nursery_id'  => $nurseryId,
                'proposed_by' => $proposedBy,
                'payload'     => $payload,
                'status'      => ProposalStatus::PENDING,
            ]);

            $this->events->publish(new ProductProposalFiled(
                proposalUuid: $proposal->uuid,
                nurseryId: $nurseryId,
                suggestedName: $payload['name'] ?? null,
            ));

            return $proposal;
        });
    }

    /** Approve a pending proposal, materializing a DRAFT product from its payload. */
    public function approve(ProductProposal $proposal, ?string $reviewerUuid, ?string $note = null): ProductProposal
    {
        return DB::transaction(function () use ($proposal, $reviewerUuid, $note) {
            // Lock + re-read so two concurrent approvals can't both materialize.
            $locked = $this->lockPending($proposal, 'approved');

            $payload = $locked->payload ?? [];
            if (empty($payload['name'])) {
                throw DomainActionException::unprocessable('Proposal payload must include a product name.', 'INVALID_PROPOSAL', 'payload');
            }

            // Materialize as a DRAFT — admin curates and publishes separately.
            $product = $this->products->create([
                'name'           => $payload['name'],
                'slug'           => $payload['slug'] ?? $payload['name'],
                'botanical_name' => $payload['botanical_name'] ?? null,
                'hindi_name'     => $payload['hindi_name'] ?? null,
                'description'    => $payload['description'] ?? null,
                'care'           => $payload['care'] ?? null,
                'category_uuid'  => $payload['category_uuid'] ?? null,
                'status'         => ProductStatus::DRAFT,
            ], $reviewerUuid);

            $locked->update([
                'status'      => ProposalStatus::APPROVED,
                'review_note' => $note,
                'reviewed_by' => $reviewerUuid,
                'reviewed_at' => Carbon::now(),
                'product_id'  => $product->id,
            ]);

            return $locked->fresh('product');
        });
    }

    public function reject(ProductProposal $proposal, ?string $reviewerUuid, ?string $note = null): ProductProposal
    {
        return DB::transaction(function () use ($proposal, $reviewerUuid, $note) {
            $locked = $this->lockPending($proposal, 'rejected');

            $locked->update([
                'status'      => ProposalStatus::REJECTED,
                'review_note' => $note,
                'reviewed_by' => $reviewerUuid,
                'reviewed_at' => Carbon::now(),
            ]);

            return $locked;
        });
    }

    /** Lock the proposal row and confirm it is still pending, else 409. */
    private function lockPending(ProductProposal $proposal, string $action): ProductProposal
    {
        $locked = ProductProposal::whereKey($proposal->getKey())->lockForUpdate()->first();

        if (! $locked || $locked->status !== ProposalStatus::PENDING) {
            throw DomainActionException::conflict(
                "Only a pending proposal can be {$action}.",
                'PROPOSAL_NOT_PENDING',
            );
        }

        return $locked;
    }
}
