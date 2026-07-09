<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\Permission;
use Marvel\Http\Rules\UniqueBankAccount;
use Marvel\Http\Rules\UniquePhone;


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
        $rules = [
            'name'                   => ['required', 'string', 'max:255'],
            'categories'             => ['array'],
            'categories.*'           => ['integer', 'exists:categories,id'],
            'is_active'              => ['boolean'],
            'description'            => ['nullable', 'string', 'max:10000'],
            // Vendor profile fields (validate format whenever provided).
            'contact_person'         => ['nullable', 'string', 'max:191'],
            // Phone must be unique across vendors — checked against BOTH the
            // mobile column and the legacy settings->contact JSON path.
            'mobile'                 => ['nullable', 'string', 'regex:/^[0-9]{10}$/', new UniquePhone(null)],
            'upi'                    => ['nullable', 'string', 'regex:/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/'],
            // Vendor owner credentials — an admin-created vendor gets a dedicated login
            // (see ShopRepository::storeShop). Required for super-admins so no vendor is
            // created without a login; self-serve store owners never send owner_*.
            'owner_email'            => [
                Rule::requiredIf(fn () => (bool) $this->user()?->hasPermissionTo(Permission::SUPER_ADMIN)),
                'nullable', 'email', 'max:191', Rule::unique('users', 'email'),
            ],
            'owner_name'             => ['nullable', 'string', 'max:191'],
            'owner_password'         => ['required_with:owner_email', 'nullable', 'string', 'min:8'],
            // Bank account number lives in the balances.payment_info JSON — one vendor only.
            'balance.payment_info.account' => ['nullable', new UniqueBankAccount(null)],
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
            // Compliance / banking identifiers — validate format whenever provided.
            // The GST value is mirrored into the shops.gst_number column, so uniqueness
            // is checked against that column.
            'settings.compliance.gst'  => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/', Rule::unique('shops', 'gst_number')],
            'settings.compliance.pan'  => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'settings.banking.ifsc'    => ['nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            // KYC documents are optional at creation so onboarding is never blocked — a shop is
            // created inactive and the KYC gate is enforced at approval (go-live) instead. See
            // ShopController::approveShop.
            'settings.documents.gstCertificate' => ['nullable'],
            'settings.documents.pan'            => ['nullable'],
            'settings.documents.cheque'         => ['nullable'],
        ];

        return $rules;
    }

    /**
     * Friendly copy for the uniqueness / owner-credential rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'owner_email.unique'           => 'A user with this email already exists — use a different login email or transfer ownership instead.',
            'owner_email.required'         => 'Owner login email is required.',
            'owner_password.required_with' => 'Set a login password for the vendor.',
            'mobile.unique'                => 'Another vendor is already registered with this mobile number.',
            'settings.compliance.gst.unique' => 'This GSTIN is already registered to another vendor.',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
