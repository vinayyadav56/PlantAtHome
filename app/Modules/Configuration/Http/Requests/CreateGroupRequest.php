<?php

namespace App\Modules\Configuration\Http\Requests;

use App\Modules\Configuration\Domain\SelectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:config.manage
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:64', 'unique:config_groups,code'],
            'name'        => ['required', 'string', 'max:255'],
            'select_type' => ['nullable', Rule::in(SelectType::ALL)],
            'is_required' => ['nullable', 'boolean'],
            'sort'        => ['nullable', 'integer', 'min:0'],
        ];
    }
}
