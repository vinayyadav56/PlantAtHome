<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_uuid' => ['required', 'uuid'],
            'nursery_id'   => ['required', 'uuid'],
            'selection'    => ['nullable', 'array'],   // { group_code: [option_uuid,…] }
            'qty'          => ['nullable', 'integer', 'min:1', 'max:99'],
            'city'         => ['nullable', 'string', 'max:64'],
        ];
    }
}
