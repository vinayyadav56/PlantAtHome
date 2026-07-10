<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor's coverage of a city: a delivery radius around a centre point. A
 * null centre / zero radius means the whole city is served.
 *
 * @property string      $nursery_id
 * @property int         $city_id
 * @property string      $delivery_radius_km
 * @property string|null $center_lat
 * @property string|null $center_lng
 * @property bool        $is_active
 */
class VendorArea extends Model
{
    use HasUuid;

    protected $table = 'svc_vendor_areas';

    protected $fillable = [
        'uuid', 'nursery_id', 'city_id', 'delivery_radius_km', 'center_lat', 'center_lng', 'is_active',
    ];

    protected $casts = [
        'delivery_radius_km' => 'decimal:2',
        'is_active'          => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
