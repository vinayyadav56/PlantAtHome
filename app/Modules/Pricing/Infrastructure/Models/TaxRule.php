<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $category_uuid
 * @property string|null $hsn_code
 * @property string      $gst_rate
 */
class TaxRule extends Model
{
    use HasUuid;

    protected $table = 'pricing_tax_rules';

    protected $fillable = ['uuid', 'name', 'category_uuid', 'hsn_code', 'gst_rate'];

    protected $casts = ['gst_rate' => 'decimal:2'];
}
