<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A competitor listing the admin is tracking for price. Price points live in
 * market_price_snapshots (one per refresh); last_* mirror the newest snapshot.
 */
class MarketWatchlistItem extends Model
{
    protected $table = 'market_watchlist';

    protected $guarded = [];

    protected $casts = [
        'last_price'            => 'float',
        'last_price_mrp'        => 'float',
        'last_discount_percent' => 'float',
        'last_refreshed_at'     => 'datetime',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(MarketPriceSnapshot::class, 'watchlist_id');
    }
}
