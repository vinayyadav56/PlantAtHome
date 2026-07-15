<?php

namespace App\Modules\Nursery\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A nursery (vendor). Mirrors a legacy `shops` row when legacy_id is set;
 * v2-native nurseries have legacy_id null. owner_user_uuid points into
 * Identity by uuid — no cross-module FK.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int|null    $legacy_id
 * @property string|null $owner_user_uuid
 * @property string      $name
 * @property string|null $slug
 * @property string      $status
 */
class Nursery extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'nursery_nurseries';

    protected $fillable = [
        'uuid', 'legacy_id', 'owner_user_uuid', 'name', 'slug', 'description',
        'logo', 'cover_image', 'address', 'status', 'commission_rate', 'settings',
        'contact_person', 'mobile', 'upi', 'lat', 'lng', 'category_ids',
        'service_areas', 'gst_number',
    ];

    protected $casts = [
        'logo'          => 'array',
        'cover_image'   => 'array',
        'address'       => 'array',
        'settings'      => 'array',
        'category_ids'  => 'array',
        'service_areas' => 'array',
    ];

    public function balance(): HasOne
    {
        return $this->hasOne(NurseryBalance::class, 'nursery_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(NurseryDocument::class, 'nursery_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(NurseryWithdrawal::class, 'nursery_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(NurseryLedgerEntry::class, 'nursery_id');
    }
}
