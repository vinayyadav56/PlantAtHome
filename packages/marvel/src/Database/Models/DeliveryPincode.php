<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPincode extends Model
{
    protected $table = 'delivery_pincodes';

    protected $fillable = [
        'pincode',
        'area',
        'city',
        'state',
        'is_active',
        'cod_enabled',
        'eta_days',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'cod_enabled' => 'boolean',
        'eta_days'    => 'integer',
    ];
}
