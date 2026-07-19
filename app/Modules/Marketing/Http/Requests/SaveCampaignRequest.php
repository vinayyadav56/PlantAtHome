<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Requests;

use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $scheduleType = (string) $this->input('schedule_type');

        return [
            'name'          => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:500'],
            'audience_uuid' => [$creating ? 'required' : 'sometimes', 'string'],

            'channels'      => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'channels.*'    => ['in:email,sms,whatsapp'],

            'templates'              => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'templates.*.channel'      => ['required', 'in:email,sms,whatsapp'],
            'templates.*.template_uuid' => ['required', 'string'],

            'schedule_type'   => ['sometimes', 'in:now,once,daily,weekly,monthly,cron'],
            'scheduled_at'    => [Rule::requiredIf(in_array($scheduleType, ['once'], true)), 'nullable', 'date'],
            'cron_expression' => [
                Rule::requiredIf($scheduleType === 'cron'),
                'nullable', 'string', 'max:120',
                // Reject a garbage cron up front — otherwise it silently never fires.
                function ($attribute, $value, $fail) {
                    if ($value !== null && $value !== '' && ! CronExpression::isValidExpression((string) $value)) {
                        $fail('The cron expression is not valid.');
                    }
                },
            ],
            'timezone'        => ['nullable', 'string', 'max:64'],

            'batch_size'          => ['nullable', 'integer', 'min:1', 'max:100000'],
            'throttle_per_minute' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'send_delay_seconds'  => ['nullable', 'integer', 'min:0', 'max:86400'],
            'priority'            => ['nullable', 'integer', 'min:-10', 'max:10'],
            'max_retries'         => ['nullable', 'integer', 'min:0', 'max:10'],
        ];
    }
}
