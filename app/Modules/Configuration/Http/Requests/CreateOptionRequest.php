<?php

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:config.manage
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'sku'          => ['nullable', 'string', 'max:64', 'unique:config_options,sku'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'base_price'   => ['nullable', 'numeric', 'min:0'],
            'currency'     => ['nullable', 'string', 'size:3'],
            'status'       => ['nullable', 'in:active,inactive'],
            'sort'         => ['nullable', 'integer', 'min:0'],
        ];
    }
}
