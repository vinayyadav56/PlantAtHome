<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


class UserCreateRequest extends FormRequest
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
            // Either the legacy single `name` or the structured first_name is
            // required; old clients keep working, the User::saving observer
            // converges the two representations.
            'name'       => ['required_without:first_name', 'nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:120'],
            'last_name'  => ['nullable', 'string', 'max:120'],
            'email'    => ['required', 'email', 'unique:users'],
            // The phone drives OTP login, and otpLogin logs into the FIRST profile holding a
            // number — so a second account claiming the same number makes login
            // nondeterministic. Until now /register ignored `contact` entirely and the
            // storefront's follow-up PUT /me/contacts had no uniqueness rule (and swallowed
            // its own errors), so duplicates were silently created. Reject here, BEFORE the
            // account exists, as a field error the form can show under the phone input.
            // Same normalisation and message as UserController::updateContact.
            'contact'  => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                $normalized = \Marvel\Http\Rules\UniquePhone::normalize($value);
                if ($normalized === null) {
                    return; // empty / too short — format is checked by the client schema
                }
                if (\Marvel\Database\Models\Profile::where('contact_clean', $normalized)->exists()) {
                    $fail('This phone number is already linked to another account.');
                }
            }],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)],
            'shop_id' => ['nullable', 'exists:Marvel\Database\Models\Shop,id'],
            'profile'  => ['array'],
            'address'  => ['array'],
            // 'shop'  => ['array'],
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.required_without'       => 'Name is required',
            'first_name.required_without' => 'First name is required',
            'name.string'        => 'Name is not a valid string',
            'name.max:255'       => 'Name can not be more than 255 character',
            'email.required'     => 'email is required',
            'email.email'        => 'email is not a valid email address',
            'email.unique'       => 'This email is already registered. Sign in instead, or use a different email.',
            'password.required'  => 'password is required',
            'password.string'    => 'password is not a valid string',
            'address.array'      => 'address is not a valid json',
            'profile.array'      => 'profile is not a valid json',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
