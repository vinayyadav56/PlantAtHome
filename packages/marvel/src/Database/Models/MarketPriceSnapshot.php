<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured price point for a watchlist item (recorded on each refresh).
 */
class MarketPriceSnapshot extends Model
{
    protected $table = 'market_price_snapshots';

    public $timestamps = false; // captured_at + created_at set explicitly / by DB default

    protected $guarded = [];

    protected $casts = [
        'price_current'    => 'float',
        'price_mrp'        => 'float',
        'discount_percent' => 'float',
        'captured_at'      => 'datetime',
        'created_at'       => 'datetime',
    ];

    public function watchlistItem(): BelongsTo
    {
        return $this->belongsTo(MarketWatchlistItem::class, 'watchlist_id');
    }
}
