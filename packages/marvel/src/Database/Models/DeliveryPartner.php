<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery partner (rider). Backed by a `user` login + KYC + geocoded base
 * location + commission config. `is_vendor_cum_dp` + `shop_id` link it to a
 * vendor shop that also delivers.
 */
class DeliveryPartner extends Model
{
    use SoftDeletes;

    protected $table = 'delivery_partners';

    public $guarded = [];

    protected $casts = [
        'aadhaar_number'   => 'encrypted',
        'pan_number'       => 'encrypted',
        'aadhaar_doc'      => 'json',
        'pan_doc'          => 'json',
        'live_photo'       => 'json',
        'address'          => 'json',
        'location'         => 'json',
        'payment_info'     => 'json',
        'is_vendor_cum_dp' => 'boolean',
        'is_active'        => 'boolean',
        'lat'              => 'float',
        'lng'              => 'float',
        'commission_value' => 'float',
        'courier_commission_value' => 'float',
    ];

    /** Masked KYC for non-admin contexts (e.g. the DP's own dashboard). */
    protected $appends = ['aadhaar_masked', 'pan_masked'];

    public function getAadhaarMaskedAttribute(): ?string
    {
        $v = $this->aadhaar_number;
        return $v ? 'XXXX-XXXX-' . substr(preg_replace('/\D/', '', $v), -4) : null;
    }

    public function getPanMaskedAttribute(): ?string
    {
        $v = $this->pan_number;
        return $v ? substr($v, 0, 2) . 'XXXXX' . substr($v, -1) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(DeliveryPartnerBalance::class, 'delivery_partner_id');
    }

    public function withdraws(): HasMany
    {
        return $this->hasMany(DeliveryPartnerWithdraw::class, 'delivery_partner_id');
    }
}
