<?php

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Inventory\Domain\SellableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BasePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:pricing.manage
    }

    public function rules(): array
    {
        return [
            'sellable_type' => ['required', Rule::in(SellableType::ALL)],
            'sellable_uuid' => ['required', 'uuid'],
            'amount'        => ['required', 'numeric', 'min:0'],
            'currency'      => ['nullable', 'string', 'size:3'],
        ];
    }
}
