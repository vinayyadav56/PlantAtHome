<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int   $id
 * @property int   $attribute_id
 * @property int   $product_id
 * @property mixed $value
 */
class AttributeValue extends Model
{
    protected $table = 'catalog_attribute_values';

    protected $fillable = ['attribute_id', 'product_id', 'value'];

    protected $casts = ['value' => 'array'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
