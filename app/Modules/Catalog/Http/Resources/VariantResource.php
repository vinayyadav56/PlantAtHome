<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\ProductVariant;

final class VariantResource
{
    public static function make(ProductVariant $v): array
    {
        return [
            'uuid'         => $v->uuid,
            'sku'          => $v->sku,
            'size_code'    => $v->size_code,
            'name'         => $v->name,
            'weight_grams' => $v->weight_grams,
            'dimensions'   => $v->dimensions,
            'status'       => $v->status,
            'sort'         => $v->sort,
        ];
    }
}
