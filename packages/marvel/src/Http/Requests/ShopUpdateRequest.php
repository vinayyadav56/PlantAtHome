<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Http\Rules\EmailInUse;
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
            'balance.payment_info.email'   => ['nullable', 'email', new EmailInUse((int) $shopId)],
            'image'                  => ['nullable', 'array'],
            'cover_image'            => ['nullable', 'array'],
            'settings'               => ['array'],
            'address'                => ['array'],
            // Vendor address = a courier PICKUP address (see ShopCreateRequest).
            // Complete-on-use: enforced whenever the form sends an address, so a
            // vendor with legacy incomplete data fixes it at the next save.
            'address.street_address' => ['required_with:address', 'string', 'max:255'],
            'address.house_no'       => ['nullable', 'string', 'max:120'],
            'address.street_address2' => ['nullable', 'string', 'max:255'],
            'address.area'           => ['nullable', 'string', 'max:120'],
            'address.landmark'       => ['nullable', 'string', 'max:255'],
            'address.city'           => ['required_with:address', 'string', 'max:120'],
            'address.state'          => ['required_with:address', 'string', 'max:120'],
            'address.zip'            => ['required_with:address', 'regex:/^[1-9][0-9]{5}$/'],
            'address.country'        => ['nullable', 'string', 'max:64'],
            'lat'                    => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                    => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_mode'               => ['sometimes', 'in:platform,self'],
            'self_delivery'               => ['nullable', 'array'],
            'self_delivery.contact_name'  => ['nullable', 'string', 'max:120'],
            'self_delivery.contact_phone' => ['nullable', 'string', 'max:20'],
            'self_delivery.radius_km'     => ['nullable', 'numeric', 'min:0', 'max:500'],
            'self_delivery.same_day'      => ['nullable', 'boolean'],
            'self_delivery.cod'           => ['nullable', 'boolean'],
            'self_delivery.days'          => ['nullable', 'string', 'max:255'],
            'self_delivery.hours'         => ['nullable', 'string', 'max:255'],
            'self_delivery.notes'         => ['nullable', 'string', 'max:500'],
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
            'address.street_address.required_with' => 'Street address (with house/plot number) is required',
            'address.city.required_with'           => 'City is required',
            'address.state.required_with'          => 'State is required',
            'address.zip.required_with'            => 'PIN code is required',
            'address.zip.regex'                    => 'Enter a valid 6-digit PIN code',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
