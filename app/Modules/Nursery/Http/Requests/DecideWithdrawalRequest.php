<?php

namespace App\Modules\Nursery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:withdrawals.manage
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject', 'on_hold', 'processing'])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
