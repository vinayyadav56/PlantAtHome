<?php

namespace App\Modules\Nursery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:nurseries.manage
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
