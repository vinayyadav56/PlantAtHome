<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Geo master root (Delivery Coverage). India is the only seeded row today.
 *
 * @property int    $id
 * @property string $name
 * @property string $iso2
 * @property bool   $is_active
 */
class Country extends Model
{
    protected $table = 'countries';

    protected $fillable = ['name', 'iso2', 'iso3', 'phone_code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
