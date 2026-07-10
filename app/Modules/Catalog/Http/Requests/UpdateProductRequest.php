<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage
    }

    public function rules(): array
    {
        return [
            'name'                   => ['sometimes', 'string', 'max:255'],
            'slug'                   => ['sometimes', 'string', 'max:255'],
            'category_uuid'          => ['sometimes', 'nullable', 'uuid', 'exists:catalog_categories,uuid'],
            'botanical_name'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'hindi_name'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'description'            => ['sometimes', 'nullable', 'string'],
            'care'                   => ['sometimes', 'nullable', 'array'],
            'seo_title'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description'        => ['sometimes', 'nullable', 'string', 'max:1000'],
            'attributes'             => ['sometimes', 'array'],
            'attributes.*.attribute' => ['required_with:attributes', 'string'],
            'attributes.*.value'     => ['present'],
        ];
    }
}
