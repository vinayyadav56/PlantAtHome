<?php

namespace Marvel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\PaymentGatewayType;

class OrderCreateRequest extends FormRequest
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
            'coupon_id'               => 'nullable|exists:Marvel\Database\Models\Coupon,id',
            'shop_id'                 => 'nullable|exists:Marvel\Database\Models\Shop,id',
            'customer_id'             => 'nullable|exists:Marvel\Database\Models\User,id',
            'language'                => ['nullable', 'string'],
            'amount'                  => 'required|numeric',
            'paid_total'              => 'required|numeric',
            'total'                   => 'required|numeric',
            'delivery_time'           => 'nullable|string',
            'customer_contact'        => 'nullable|string',
            'customer_name'           => 'nullable|string',
            'payment_gateway'         => ['required', Rule::in(PaymentGatewayType::getValues())],
            'altered_payment_gateway' => 'nullable|string',
            'products'                => 'required|array|max:100', // cap lines: each is O(1+) DB queries in storeOrder
            'card'                    => 'array',
            'token'                   => 'nullable|string',
            'use_wallet_points'       => 'nullable|boolean',
            'shipping_address'        => 'array',
            'billing_address'         => 'array',
            'note'                    => 'nullable|string',
            'is_non_serviceable_order' => 'nullable|boolean',
            'detected_city'           => 'nullable|string',
            'serviceable_city'        => 'nullable|string',
        ];
    }


    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
