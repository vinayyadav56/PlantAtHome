<?php

namespace App\Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Note: there is deliberately NO price/amount field here. The server recomputes
 * every price from identifiers only, so a client-posted amount cannot be
 * injected (Section 7 anti-tamper).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_uuid'  => ['required', 'uuid'],
            'nursery_id'    => ['required', 'uuid'],
            'qty'           => ['nullable', 'integer', 'min:1', 'max:999'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'category_uuid' => ['nullable', 'uuid'],
            'city'          => ['nullable', 'string', 'max:64'],
            'options'       => ['nullable', 'array'],
            'options.*'     => ['uuid'],
            'facts'         => ['nullable', 'array'],
            'coupon'        => ['nullable', 'string', 'max:64'],
        ];
    }
}
