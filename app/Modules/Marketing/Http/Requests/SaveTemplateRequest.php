<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $channel = (string) $this->input('channel');
        $needsBody = in_array($channel, ['sms', 'whatsapp'], true);

        return [
            'name'            => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'channel'         => [$creating ? 'required' : 'sometimes', 'in:email,sms,whatsapp'],
            'description'     => ['nullable', 'string', 'max:500'],
            'status'          => ['sometimes', 'in:draft,active'],
            'content'         => [$creating ? 'required' : 'sometimes', 'array'],
            'content.subject' => [Rule::requiredIf($creating && $channel === 'email'), 'nullable', 'string', 'max:255'],
            'content.html'    => ['nullable', 'string'],
            'content.text'    => ['nullable', 'string'],
            'content.header'  => ['nullable', 'string'],
            'content.footer'  => ['nullable', 'string'],
            'content.body'    => [Rule::requiredIf($creating && $needsBody), 'nullable', 'string'],
            'content.buttons'          => ['nullable', 'array'],
            'content.buttons.*.label'  => ['nullable', 'string', 'max:60'],
            'content.buttons.*.url'    => ['nullable', 'string', 'max:2048'],
            'content.media'   => ['nullable', 'string', 'max:2048'],
            'content.unicode' => ['nullable', 'boolean'],
            'content.template_name' => ['nullable', 'string', 'max:255'],
            'content.lang'    => ['nullable', 'string', 'max:16'],
        ];
    }
}
