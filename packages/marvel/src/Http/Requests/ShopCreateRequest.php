<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class ShopCreateRequest extends FormRequest
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
            'name'                   => ['required', 'string', 'max:255'],
            'categories'             => ['array'],
            'is_active'              => ['boolean'],
            'description'            => ['nullable', 'string', 'max:10000'],
            'admin_commission_rate'  => ['nullable', 'numeric'],
            'total_earnings'         => ['nullable', 'numeric'],
            'withdrawn_amount'       => ['nullable', 'numeric'],
            'current_balance'        => ['nullable', 'numeric'],
            'image'                  => ['nullable', 'array'],
            'cover_image'            => ['nullable', 'array'],
            'settings'               => ['array'],
            'address'                => ['array'],
            'lat'                    => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                    => ['nullable', 'numeric', 'between:-180,180'],
            'service_areas'                    => ['nullable', 'array'],
            'service_areas.*.city'             => ['required_with:service_areas', 'string', 'max:100'],
            'service_areas.*.pincode'          => ['nullable', 'string', 'max:12'],
            'service_areas.*.fulfillment_mode' => ['nullable', 'in:local,courier,both'],
            'service_areas.*.eta_days'         => ['nullable', 'integer', 'min:0', 'max:60'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
