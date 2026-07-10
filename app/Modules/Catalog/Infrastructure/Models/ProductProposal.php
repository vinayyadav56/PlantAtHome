<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nursery's suggestion for a new master-catalog product, awaiting admin
 * review. nursery_id is the opaque vendor scope; there is no product row until
 * an admin approves it.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $nursery_id
 * @property array       $payload
 * @property string      $status
 * @property int|null    $product_id
 */
class ProductProposal extends Model
{
    use HasUuid;

    protected $table = 'catalog_product_proposals';

    protected $fillable = [
        'uuid', 'nursery_id', 'proposed_by', 'payload', 'status',
        'review_note', 'reviewed_by', 'reviewed_at', 'product_id',
    ];

    protected $casts = [
        'payload'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
