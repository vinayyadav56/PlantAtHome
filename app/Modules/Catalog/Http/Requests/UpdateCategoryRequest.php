<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'string', 'max:255'],
            'slug'            => ['sometimes', 'string', 'max:255'],
            'sort'            => ['sometimes', 'integer', 'min:0'],
            'status'          => ['sometimes', 'in:active,inactive'],
            'seo_title'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Re-parenting (which would require a path/depth subtree rewrite) is
            // not supported yet — reject it loudly rather than silently ignoring.
            'parent_uuid'     => ['prohibited'],
        ];
    }
}
