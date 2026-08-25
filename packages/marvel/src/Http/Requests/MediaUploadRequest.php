<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MediaUploadRequest extends FormRequest
{
    /**
     * Route middleware (permission:media.upload) gates access.
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
            // No SVG/HTML (stored-XSS) — same safe-image set as AttachmentRequest.
            'file'        => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:' . config('media.max_upload_kb')],
            'entity_hint' => ['nullable', 'string', 'max:40'],
            'alt'         => ['nullable', 'string', 'max:255'],
            'attribution' => ['nullable', 'string', 'max:500'],
            'source'      => ['nullable', 'string', 'max:30'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
