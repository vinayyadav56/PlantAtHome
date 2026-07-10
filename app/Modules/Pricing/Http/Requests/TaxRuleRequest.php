<?php

namespace App\Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaxRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:pricing.manage
    }

    public function rules(): array
    {
        return [
            'category_uuid' => ['nullable', 'uuid'],
            'hsn_code'      => ['nullable', 'string', 'max:16'],
            'gst_rate'      => ['required', 'numeric', 'min:0', 'max:100'],
            'name'          => ['nullable', 'string', 'max:255'],
        ];
    }
}
