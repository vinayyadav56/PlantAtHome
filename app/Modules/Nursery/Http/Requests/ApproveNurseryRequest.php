<?php

namespace App\Modules\Nursery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveNurseryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:nurseries.manage
    }

    public function rules(): array
    {
        return [
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
