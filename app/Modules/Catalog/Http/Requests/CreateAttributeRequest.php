<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\AttributeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.manage
    }

    public function rules(): array
    {
        return [
            'code'          => ['required', 'string', 'max:64', 'unique:catalog_attributes,code'],
            'name'          => ['required', 'string', 'max:255'],
            'type'          => ['required', Rule::in(AttributeType::ALL)],
            'scope'         => ['nullable', Rule::in(AttributeType::SCOPES)],
            'category_uuid' => ['nullable', 'uuid', 'required_if:scope,category', 'exists:catalog_categories,uuid'],
            'options'       => ['nullable', 'array'],
        ];
    }
}
