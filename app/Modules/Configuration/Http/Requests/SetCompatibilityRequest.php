<?php

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetCompatibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:config.manage
    }

    public function rules(): array
    {
        return [
            'rules'                 => ['present', 'array'],
            'rules.*.variant_uuid'  => ['nullable', 'uuid'],
            'rules.*.size_code'     => ['nullable', 'string', 'max:16'],
            'rules.*.is_compatible' => ['nullable', 'boolean'],
        ];
    }
}
