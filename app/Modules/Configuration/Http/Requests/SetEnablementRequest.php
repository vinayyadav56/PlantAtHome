<?php

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetEnablementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can + v1.nursery scope
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
