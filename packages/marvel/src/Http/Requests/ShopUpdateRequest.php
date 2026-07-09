<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Http\Rules\UniqueBankAccount;
use Marvel\Http\Rules\UniquePhone;

class ShopUpdateRequest extends FormRequest
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
        // The update route is registered both as /shops/{shop} and /vendors/{vendor}
        // (canonical Vendor API alias) — resolve whichever param the route carries so
        // the uniqueness checks exclude the shop being edited.
        $shopId = $this->route('shop') ?? $this->route('vendor');

        return [
            'name'                   => ['required', 'string', 'max:255'],
            'categories'             => ['array'],
            'categories.*'           => ['integer', 'exists:categories,id'],
            'is_active'              => ['boolean'],
            'description'            => ['nullable', 'string', 'max:10000'],
            'contact_person'         => ['nullable', 'string', 'max:191'],
            // Phone must be unique across vendors — checked against BOTH the
            // mobile column and the legacy settings->contact JSON path.
            'mobile'                 => ['nullable', 'string', 'regex:/^[0-9]{10}$/', new UniquePhone((int) $shopId)],
            'upi'                    => ['nullable', 'string', 'regex:/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/'],
            'balance'                => ['array'],
            // Bank account number lives in the balances.payment_info JSON — one vendor only.
            'balance.payment_info.account' => ['nullable', new UniqueBankAccount((int) $shopId)],
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
            // Compliance / banking identifiers — validate format whenever provided. Documents are
            // not re-required on update (they may already be on file from onboarding).
            // GST is mirrored into shops.gst_number, so uniqueness checks that column.
            'settings.compliance.gst'  => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/', Rule::unique('shops', 'gst_number')->ignore($shopId)],
            'settings.compliance.pan'  => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'settings.banking.ifsc'    => ['nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ];
    }

    /**
     * Friendly copy for the uniqueness rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'mobile.unique'                  => 'Another vendor is already registered with this mobile number.',
            'settings.compliance.gst.unique' => 'This GSTIN is already registered to another vendor.',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
