<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor's (shop's) cost for a product / size, for a time window. cost_price is
 * NEVER exposed to customers — it drives PricingService (margin over nearest cost)
 * and the per-order profit snapshot. is_available=false (cost 0) ⇒ "available in 6h".
 */
class VendorProductPrice extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_product_prices';

    public $guarded = [];

    protected $casts = [
        'cost_price'     => 'float',
        'is_available'   => 'boolean',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'stock_qty'      => 'integer',
        'reserved_qty'   => 'integer',
        'moq'            => 'integer',
        'lead_time_days' => 'integer',
    ];

    /** Stock a vendor can still commit (stock minus what's already reserved). */
    public function getAvailableQtyAttribute(): int
    {
        return max(0, (int) ($this->stock_qty ?? 0) - (int) ($this->reserved_qty ?? 0));
    }

    /**
     * Atomically reserve `qty` against this vendor row IF enough is available — a
     * single conditional UPDATE so concurrent orders can't oversell. Returns true on
     * success. Pair with releaseStock() on cancel and commitStock() on fulfilment.
     */
    public static function reserveStock(int $id, int $qty): bool
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return true;
        }
        return static::where('id', $id)
            ->whereRaw('(stock_qty - reserved_qty) >= ?', [$qty])
            ->update(['reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty + {$qty}")]) > 0;
    }

    /** Release a prior reservation (order cancelled). */
    public static function releaseStock(int $id, int $qty): void
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return;
        }
        static::where('id', $id)
            ->where('reserved_qty', '>=', $qty)
            ->update(['reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty - {$qty}")]);
    }

    /** Commit a reservation on fulfilment: decrement both stock and reservation. */
    public static function commitStock(int $id, int $qty): void
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return;
        }
        static::where('id', $id)
            ->where('reserved_qty', '>=', $qty)
            ->where('stock_qty', '>=', $qty)
            ->update([
                'reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty - {$qty}"),
                'stock_qty'    => \Illuminate\Support\Facades\DB::raw("stock_qty - {$qty}"),
            ]);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PriceImportBatch::class, 'import_batch_id');
    }
}
