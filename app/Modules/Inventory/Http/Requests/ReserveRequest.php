<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\SellableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReserveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:inventory.manage
    }

    public function rules(): array
    {
        return [
            'checkout_session_id'    => ['required', 'uuid'],
            'ttl_seconds'            => ['nullable', 'integer', 'min:30', 'max:3600'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.sellable_type'  => ['required', Rule::in(SellableType::ALL)],
            'items.*.sellable_uuid'  => ['required', 'uuid'],
            'items.*.nursery_id'     => ['required', 'uuid'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
        ];
    }
}
