<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical parcel of a shipment. See the migration for why the flat columns on
 * `shipments` remain the figures actually sent to the partner.
 */
class ShipmentPackage extends Model
{
    protected $table = 'shipment_packages';

    public $guarded = [];

    protected $casts = [
        'package_number' => 'integer',
        'weight_g'       => 'integer',
        'length_cm'      => 'float',
        'breadth_cm'     => 'float',
        'height_cm'      => 'float',
        'declared_value' => 'float',
        'fragile'        => 'boolean',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
