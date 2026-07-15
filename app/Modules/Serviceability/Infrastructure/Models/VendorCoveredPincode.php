<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Flattened (vendor, pincode) projection row — rewritten wholesale by
 * CoverageProjector, hence no timestamps. `source` = the winning rule tier
 * (state | district | city | manual).
 *
 * @property int      $shop_id
 * @property string   $pincode
 * @property string   $source
 * @property int|null $state_id
 * @property int|null $district_id
 * @property int|null $city_id
 */
class VendorCoveredPincode extends Model
{
    public $timestamps = false;

    protected $table = 'vendor_covered_pincodes';

    protected $fillable = ['shop_id', 'pincode', 'source', 'state_id', 'district_id', 'city_id'];
}
