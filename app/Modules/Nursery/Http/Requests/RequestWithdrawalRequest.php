<?php

namespace App\Modules\Nursery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // owner-or-admin check happens in the controller
    }

    public function rules(): array
    {
        return [
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'details'        => ['nullable', 'string', 'max:2000'],
        ];
    }
}
