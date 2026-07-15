<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per unique pincode (dominant district when a pin spans several);
 * `offices` keeps up to 6 {name, taluk} delivery offices for display.
 *
 * @property int         $id
 * @property int         $country_id
 * @property int         $state_id
 * @property int         $district_id
 * @property int|null    $city_id
 * @property string      $pincode
 * @property string      $status
 * @property array|null  $offices
 */
class PostalCode extends Model
{
    public const STATUS_ACTIVE = 'active';

    protected $table = 'postal_codes';

    protected $fillable = [
        'country_id', 'state_id', 'district_id', 'city_id', 'pincode',
        'office_name', 'offices', 'latitude', 'longitude', 'status',
    ];

    protected $casts = ['offices' => 'array'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
