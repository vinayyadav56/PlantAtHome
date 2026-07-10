<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage middleware
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'slug'            => ['nullable', 'string', 'max:255'],
            'parent_uuid'     => ['nullable', 'uuid', 'exists:catalog_categories,uuid'],
            'sort'            => ['nullable', 'integer', 'min:0'],
            'status'          => ['nullable', 'in:active,inactive'],
            'seo_title'       => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
