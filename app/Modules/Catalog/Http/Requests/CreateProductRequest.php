<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'slug'                  => ['nullable', 'string', 'max:255'],
            'category_uuid'         => ['nullable', 'uuid', 'exists:catalog_categories,uuid'],
            'botanical_name'        => ['nullable', 'string', 'max:255'],
            'hindi_name'            => ['nullable', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'care'                  => ['nullable', 'array'],
            'status'                => ['nullable', Rule::in(ProductStatus::ALL)],
            'seo_title'             => ['nullable', 'string', 'max:255'],
            'seo_description'       => ['nullable', 'string', 'max:1000'],

            'variants'              => ['nullable', 'array'],
            'variants.*.sku'        => ['nullable', 'string', 'max:64'],
            'variants.*.size_code'  => ['nullable', 'string', 'max:16'],
            'variants.*.name'       => ['nullable', 'string', 'max:255'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0'],
            'variants.*.dimensions' => ['nullable', 'array'],

            'media'                 => ['nullable', 'array'],
            'media.*.url'           => ['required_with:media', 'string', 'max:2048'],
            'media.*.type'          => ['nullable', 'in:image,video'],
            'media.*.alt'           => ['nullable', 'string', 'max:255'],

            'attributes'            => ['nullable', 'array'],
            'attributes.*.attribute' => ['required_with:attributes', 'string'],
            'attributes.*.value'    => ['present'],
        ];
    }
}
