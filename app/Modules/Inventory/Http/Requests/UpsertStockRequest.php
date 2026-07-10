<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\SellableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can + nursery-scope in the controller
    }

    public function rules(): array
    {
        return [
            'sellable_type' => ['required', Rule::in(SellableType::ALL)],
            'sellable_uuid' => ['required', 'uuid'],
            'nursery_id'    => ['required', 'uuid'],
            'qty_on_hand'   => ['required', 'integer', 'min:0'],
            'track'         => ['nullable', 'boolean'],
        ];
    }
}
