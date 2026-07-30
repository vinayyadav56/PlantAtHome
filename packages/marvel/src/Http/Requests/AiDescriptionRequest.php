<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\AiType;

class AiDescriptionRequest extends FormRequest
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
            // Cap the prompt — this relays to a paid LLM, so an unbounded prompt
            // is a cost/abuse vector (route is public + throttled).
            'prompt'                  => ['string', 'required', 'max:2000'],
            'language'                => ['string', 'nullable', 'max:16'],
            // Generation options (all optional): length may be a keyword
            // (short|medium|long) or a character count; tone/keywords are free text.
            'length'                  => ['nullable', 'string', 'max:20'],
            'tone'                    => ['nullable', 'string', 'max:40'],
            'keywords'                => ['nullable', 'string', 'max:500'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
