<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


class AttachmentRequest extends FormRequest
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
            // SECURITY: bound the upload (was just 'required' → unlimited files of any type/size:
            // disk/CPU DoS + SVG/HTML stored-XSS). Cap count, restrict to safe image/pdf mimes
            // (no SVG/HTML), cap per-file size at 5MB.
            'attachment'        => ['required', 'array', 'max:10'],
            'attachment.*'      => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:5120'],
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
