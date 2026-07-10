<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\ProductProposal;

final class ProposalResource
{
    public static function make(ProductProposal $p): array
    {
        return [
            'uuid'         => $p->uuid,
            'nursery_id'   => $p->nursery_id,
            'proposed_by'  => $p->proposed_by,
            'payload'      => $p->payload,
            'status'       => $p->status,
            'review_note'  => $p->review_note,
            'reviewed_by'  => $p->reviewed_by,
            'reviewed_at'  => $p->reviewed_at?->toIso8601String(),
            'product_uuid' => $p->relationLoaded('product') ? $p->product?->uuid : null,
            'created_at'   => $p->created_at?->toIso8601String(),
        ];
    }
}
