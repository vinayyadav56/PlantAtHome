<?php

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:config.manage
    }

    public function rules(): array
    {
        return [
            'group_uuid'           => ['required', 'uuid', 'exists:config_groups,uuid'],
            'is_required_override' => ['nullable', 'boolean'],
            'default_option_uuid'  => ['nullable', 'uuid'],
            'sort'                 => ['nullable', 'integer', 'min:0'],
        ];
    }
}
