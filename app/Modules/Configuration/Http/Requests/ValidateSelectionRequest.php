<?php

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant'   => ['required', 'uuid'],
            'selection' => ['present', 'array'],   // { group_code: [option_uuid,...] }
            'city'      => ['nullable', 'string', 'max:64'],
            'nursery'   => ['nullable', 'uuid'],
        ];
    }
}
