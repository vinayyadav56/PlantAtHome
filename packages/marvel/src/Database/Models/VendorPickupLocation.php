<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical door a vendor loads from, mapped 1:1 to a partner pickup location.
 * shops.pickup_location_name could only ever name a single one and carried no state;
 * this row carries the address, the partner's own id for it, and whether the partner
 * has actually accepted it.
 */
class VendorPickupLocation extends Model
{
    protected $table = 'vendor_pickup_locations';

    /**
     * Explicit whitelist rather than the guarded=[] used elsewhere in this namespace:
     * `status`, `provider_pickup_code` and `provider_location_name` are the partner's
     * answer, not the operator's input, and a vendor-facing form must not be able to
     * declare its own location verified.
     */
    protected $fillable = [
        'shop_id', 'label', 'contact_name', 'phone', 'address', 'address_2',
        'city', 'state', 'country', 'pincode', 'lat', 'lng', 'partner', 'is_default',
    ];

    protected $casts = [
        'lat'            => 'float',
        'lng'            => 'float',
        'is_default'     => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    /**
     * Safe to send as `pickup_location`. Both halves matter: a nickname the partner
     * never accepted 422s the booking, and a verified row with no nickname is a
     * half-finished registration.
     */
    public function isUsable(): bool
    {
        return $this->status === 'verified' && trim((string) $this->provider_location_name) !== '';
    }
}
