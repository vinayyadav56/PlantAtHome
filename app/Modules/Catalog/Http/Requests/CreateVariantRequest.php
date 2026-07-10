<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage
    }

    public function rules(): array
    {
        return [
            'sku'          => ['nullable', 'string', 'max:64', 'unique:catalog_product_variants,sku'],
            'size_code'    => ['nullable', 'string', 'max:16'],
            'name'         => ['nullable', 'string', 'max:255'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'dimensions'   => ['nullable', 'array'],
            'status'       => ['nullable', 'in:active,inactive'],
            'sort'         => ['nullable', 'integer', 'min:0'],
        ];
    }
}
