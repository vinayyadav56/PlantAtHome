<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property bool   $is_active
 */
class City extends Model
{
    use HasUuid;

    protected $table = 'svc_cities';

    protected $fillable = ['uuid', 'name', 'state', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function vendorAreas(): HasMany
    {
        return $this->hasMany(VendorArea::class, 'city_id');
    }
}
