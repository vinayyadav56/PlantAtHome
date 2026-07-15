<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Administrative district under a legacy `states` row (Delivery Coverage).
 *
 * @property int    $id
 * @property int    $state_id
 * @property string $name
 * @property bool   $is_active
 */
class District extends Model
{
    protected $table = 'districts';

    protected $fillable = ['state_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function postalCodes(): HasMany
    {
        return $this->hasMany(PostalCode::class, 'district_id');
    }
}
