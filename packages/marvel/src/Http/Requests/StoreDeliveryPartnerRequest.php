<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates delivery-partner onboarding input. The route is already SUPER_ADMIN
 * gated, so authorize() is true. KYC + bank are optional but format-validated when
 * present (Aadhaar 12 digits, PAN AAAAA9999A, IFSC ^[A-Z]{4}0[A-Z0-9]{6}$, account
 * 9-18 digits). This rejects malformed input at the boundary on top of Eloquent's
 * parameterized queries — the controller never builds raw SQL from request input.
 */
class StoreDeliveryPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize codes to upper-case so the format regexes match user casing. */
    protected function prepareForValidation(): void
    {
        $patch = [];
        if ($this->filled('pan_number')) {
            $patch['pan_number'] = strtoupper(trim((string) $this->input('pan_number')));
        }
        if ($this->filled('ifsc_code')) {
            $patch['ifsc_code'] = strtoupper(trim((string) $this->input('ifsc_code')));
        }
        if ($patch) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Identity
            'first_name'          => ['required', 'string', 'max:100'],
            'last_name'           => ['nullable', 'string', 'max:100'],
            'mobile'              => ['required', 'string', 'regex:/^[+0-9][0-9\-\s]{7,19}$/'],
            'email'               => ['nullable', 'email', 'max:255'],
            'password'            => ['nullable', 'string', 'min:8'],
            'vehicle_type'        => ['nullable', 'string', 'max:100'],
            'is_vendor_cum_dp'    => ['nullable', 'boolean'],
            'shop_id'             => ['nullable', 'integer', 'exists:Marvel\Database\Models\Shop,id'],

            // KYC — optional, but format-validated when entered
            'aadhaar_number'      => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'pan_number'          => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar_doc'         => ['nullable', 'array'],
            'pan_doc'             => ['nullable', 'array'],
            'live_photo'          => ['nullable', 'array'],

            // Bank / payout — optional, but format-validated when entered
            'account_holder_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^\d{9,18}$/'],
            'ifsc_code'           => ['nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_name'           => ['nullable', 'string', 'max:150'],
            'branch'              => ['nullable', 'string', 'max:150'],

            // Location
            'address'             => ['nullable', 'array'],
            'location'            => ['nullable', 'array'],
            'lat'                 => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                 => ['nullable', 'numeric', 'between:-180,180'],

            // Status + commission
            'status'              => ['nullable', 'in:pending,approved,suspended'],
            'is_active'           => ['nullable', 'boolean'],
            'commission_basis'            => ['nullable', 'in:per_order,per_plant'],
            'commission_type'             => ['nullable', 'in:fixed,percentage'],
            'commission_value'            => ['nullable', 'numeric', 'min:0'],
            'courier_commission_basis'    => ['nullable', 'in:per_order,per_plant'],
            'courier_commission_type'     => ['nullable', 'in:fixed,percentage'],
            'courier_commission_value'    => ['nullable', 'numeric', 'min:0'],
            'payment_info'        => ['nullable', 'array'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'aadhaar_number.regex'      => 'Aadhaar must be 12 digits.',
            'pan_number.regex'          => 'PAN must look like ABCDE1234F.',
            'ifsc_code.regex'           => 'IFSC must be 11 characters, e.g. SBIN0001234.',
            'bank_account_number.regex' => 'Account number must be 9–18 digits.',
            'mobile.regex'              => 'Enter a valid mobile number.',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
