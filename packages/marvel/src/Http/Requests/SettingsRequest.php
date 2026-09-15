<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class SettingsRequest extends FormRequest
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
            'options' => ['required', 'array'],

            // The GST block is validated ONLY when present. `options` is a
            // free-form blob shared by every settings form, so these must never
            // fire for a form that does not touch tax.
            //
            // registration_state_code is the one that bites: GstService compares
            // the seller code against `states.code` (e.g. "HR"). Someone entering
            // the numeric GST state code ("06") gets no error, no resolvable
            // match, and isInterState() then returns false for EVERY order — so
            // out-of-state customers are silently billed CGST+SGST instead of
            // IGST. Fail loudly instead.
            'options.tax.gstin' => [
                'nullable', 'string',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            ],
            'options.tax.registration_state_code' => [
                'nullable', 'string', 'exists:states,code',
            ],
            'options.tax.delivery_tax_treatment' => [
                'nullable', 'string', 'in:follow_principal,separate,exempt',
            ],
            'options.tax.delivery_gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'options.tax.prices_include_tax' => ['nullable', 'boolean'],
        ];
    }

    public function messages()
    {
        return [
            'options.tax.gstin.regex' =>
                'GSTIN must be 15 characters, e.g. 06ABCDE1234F1Z5.',
            'options.tax.registration_state_code.exists' =>
                'Registration state code must match a state code, e.g. HR for Haryana — not the numeric GST code.',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
