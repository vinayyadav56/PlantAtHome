<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class RefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'order_id' => ['required', 'exists:Marvel\Database\Models\Order,id'],
            'title' => ['string'],
            'description' => ['string', 'nullable', 'max:10000'],
            'images' => ['array', 'nullable'],
            'refund_reason_id' => ['exists:Marvel\Database\Models\RefundReason,id'],
            // Accounting: partial / item refunds (spec §26). Amounts are recomputed server-side
            // from the order snapshot — these only say WHAT to refund, never how much money.
            'scope'                 => ['nullable', 'in:full,partial,items'],
            'requested_amount'      => ['nullable', 'numeric', 'gt:0'],
            'items'                 => ['nullable', 'array', 'min:1'],
            'items.*.order_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity'      => ['required_with:items', 'integer', 'min:1'],
            'method'                => ['nullable', 'in:wallet,gateway,manual'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
