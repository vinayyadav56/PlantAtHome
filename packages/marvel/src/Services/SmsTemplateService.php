<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Otp\Gateways\Msg91Gateway;

/**
 * Renders + sends the Airtel DLT SMS templates that live in the existing
 * notification-template engine (email_templates rows with channel = sms).
 *
 * This is glue over the existing pieces (template table, MSG91 gateway), not
 * a parallel system: callers in the existing listeners try sendByCode() and
 * fall back to the legacy blob path when it returns false — so a missing or
 * unapproved template can never break order processing.
 *
 * Guarantees:
 *  - variables substitute by SEMANTIC name ({{otp}}), in the declared order —
 *    never reordered, never guessed
 *  - a send is refused when any declared variable is missing, when the
 *    rendered body still contains {{ or {#, or when the body or any value
 *    carries a URL/email (Airtel DLT rejects unvalidatable domains)
 *  - only status=active templates with a configured MSG91 Flow ID send
 */
class SmsTemplateService
{
    private const URL_GUARD = '~(https?://|www\.|\S+@\S+\.\S+)~i';

    /** @param array<string, scalar> $vars semantic name => value */
    public function sendByCode(string $code, ?string $phone, array $vars): bool
    {
        if (empty($phone)) {
            return false;
        }
        // Templated DLT SMS only applies when the notify channel is MSG91 —
        // WhatsApp keeps its own approved Meta templates.
        $gateway = config('auth.notify_gateway') ?: config('auth.active_otp_gateway');
        if ($gateway !== 'msg91') {
            return false;
        }

        $row = $this->template($code);
        if (! $row || $row->status !== 'active' || empty($row->provider_template_id)) {
            return false;
        }

        $declared = json_decode((string) $row->variables, true) ?: [];
        $body = (string) $row->text_body;
        $ordered = [];
        foreach (array_values($declared) as $i => $def) {
            $name = $def['name'] ?? null;
            if ($name === null || ! array_key_exists($name, $vars) || ! is_scalar($vars[$name])) {
                Log::warning('sms.template.var_missing', ['code' => $code, 'var' => $name]);
                return false;
            }
            $value = (string) $vars[$name];
            if (preg_match(self::URL_GUARD, $value)) {
                Log::warning('sms.template.url_refused', ['code' => $code, 'var' => $name]);
                return false;
            }
            $body = str_replace('{{' . $name . '}}', $value, $body);
            $ordered['var' . ($i + 1)] = $value;
        }

        if (str_contains($body, '{{') || str_contains($body, '{#') || preg_match(self::URL_GUARD, $body)) {
            Log::warning('sms.template.body_refused', ['code' => $code]);
            return false;
        }

        $result = (new Msg91Gateway())->sendTemplateSms($phone, (string) $row->provider_template_id, $ordered);
        if (! $result->isValid()) {
            Log::warning('sms.order_event.send_failed', [
                'template' => $code,
                'errors' => $result->getErrors(),
            ]);
            return false;
        }
        return true;
    }

    /** MSG91 Flow/Template id of an ACTIVE registry row (OTP path). */
    public static function providerTemplateId(string $code): ?string
    {
        try {
            if (! Schema::hasColumn('email_templates', 'template_code')) {
                return null;
            }
            $row = DB::table('email_templates')
                ->where('channel', 'sms')
                ->where('template_code', $code)
                ->where('status', 'active')
                ->first(['provider_template_id']);
            return $row->provider_template_id ?: null;
        } catch (\Throwable $e) {
            return null; // stub schemas / early boot must never break auth
        }
    }

    private function template(string $code): ?object
    {
        try {
            if (! Schema::hasColumn('email_templates', 'template_code')) {
                return null;
            }
            return DB::table('email_templates')
                ->where('channel', 'sms')
                ->where('template_code', $code)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
