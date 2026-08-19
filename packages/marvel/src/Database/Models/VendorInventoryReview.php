<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable row per review transition on a vendor inventory row. Append-only:
 * there is no update path, and nothing deletes from this table — the review trail
 * must survive the row it describes (rows are soft-deleted, ids remain).
 */
class VendorInventoryReview extends Model
{
    protected $table = 'vendor_inventory_reviews';

    public const UPDATED_AT = null;

    public $guarded = [];

    /** Write one audit row. The single entry point — keeps the trail append-only. */
    public static function log(
        VendorProductPrice $row,
        ?string $previous,
        string $new,
        string $action,
        ?int $actorId = null,
        ?string $comment = null,
    ): self {
        return static::create([
            'vendor_product_price_id' => $row->id,
            'shop_id'                 => $row->shop_id,
            'product_id'              => $row->product_id,
            'variation_option_id'     => $row->variation_option_id,
            'previous_status'         => $previous,
            'new_status'              => $new,
            'action'                  => $action,
            'actor_user_id'           => $actorId,
            'comment'                 => $comment,
        ]);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(VendorProductPrice::class, 'vendor_product_price_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
