<?php

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Inventory\Domain\SellableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can + nursery-scope in controller
    }

    public function rules(): array
    {
        return [
            'nursery_id'    => ['required', 'uuid'],
            'sellable_type' => ['required', Rule::in(SellableType::ALL)],
            'sellable_uuid' => ['required', 'uuid'],
            'amount'        => ['required', 'numeric', 'min:0'],
            'currency'      => ['nullable', 'string', 'size:3'],
        ];
    }
}
