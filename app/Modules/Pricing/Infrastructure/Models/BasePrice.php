<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $sellable_type
 * @property string $sellable_uuid
 * @property string $amount
 * @property string $currency
 */
class BasePrice extends Model
{
    use HasUuid;

    protected $table = 'pricing_base_prices';

    protected $fillable = ['uuid', 'sellable_type', 'sellable_uuid', 'amount', 'currency', 'updated_by'];

    protected $casts = ['amount' => 'decimal:2'];
}
