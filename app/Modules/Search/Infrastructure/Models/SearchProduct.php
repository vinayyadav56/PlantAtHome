<?php

namespace App\Modules\Search\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $product_uuid
 * @property string      $name
 * @property string|null $category_uuid
 * @property array       $attributes
 * @property string      $status
 */
class SearchProduct extends Model
{
    protected $table = 'search_products';

    protected $fillable = [
        'product_uuid', 'name', 'slug', 'category_uuid', 'category_name',
        'attributes', 'keywords', 'price_min', 'price_max', 'status',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price_min'  => 'decimal:2',
        'price_max'  => 'decimal:2',
    ];
}
