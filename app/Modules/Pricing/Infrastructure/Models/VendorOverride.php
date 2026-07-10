<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $nursery_id
 * @property string $sellable_type
 * @property string $sellable_uuid
 * @property string $amount
 * @property string $currency
 */
class VendorOverride extends Model
{
    use HasUuid;

    protected $table = 'pricing_vendor_overrides';

    protected $fillable = ['uuid', 'nursery_id', 'sellable_type', 'sellable_uuid', 'amount', 'currency', 'updated_by'];

    protected $casts = ['amount' => 'decimal:2'];
}
