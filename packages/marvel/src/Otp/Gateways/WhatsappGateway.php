<?php

namespace Marvel\Otp\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

/**
 * WhatsApp Business (Meta Cloud API) gateway — drives BOTH:
 *   • login OTP  → startVerification / checkVerification (code generated here,
 *     cached, delivered via an approved AUTHENTICATION template, verified locally)
 *   • notifications → sendSms (the existing order-event messages are delivered as
 *     a single-variable UTILITY template, so no listener/trait changes are needed)
 *
 * Class is `WhatsappGateway` (one capital) because the resolver does
 * ucfirst('whatsapp').'Gateway' and PSR-4 autoload is case-sensitive on Linux.
 * Business-initiated WhatsApp messages require Meta-approved templates.
 * Config: config/services.php → 'whatsapp'.
 */
class WhatsappGateway implements OtpInterface
{
    private string $version;
    private ?string $phoneNumberId;
    private ?string $token;
    private ?string $otpTemplate;
    private string $otpLang;
    private bool $otpHasButton;
    private ?string $notifyTemplate;
    private string $notifyLang;

    public function __construct()
    {
        $cfg = config('services.whatsapp', []);
        $this->version = $cfg['api_version'] ?? 'v21.0';
        $this->phoneNumberId = $cfg['phone_number_id'] ?? null;
        $this->token = $cfg['access_token'] ?? null;
        $this->otpTemplate = $cfg['otp_template'] ?? null;
        $this->otpLang = $cfg['otp_lang'] ?? 'en';
        $this->otpHasButton = (bool) ($cfg['otp_has_button'] ?? false);
        $this->notifyTemplate = $cfg['notify_template'] ?? null;
        $this->notifyLang = $cfg['notify_lang'] ?? 'en';
    }

    /** E.164 digits (country code, no +). Bare 10-digit numbers → assume India. */
    private function normalize($phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phoneNumber);
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        return $digits;
    }

    private function endpoint(): string
    {
        return "https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages";
    }

    private function configured(): bool
    {
        return !empty($this->phoneNumberId) && !empty($this->token);
    }

    /** Send an approved template message; returns the WA message id. Throws on error. */
    private function sendTemplate(string $to, string $template, string $lang, array $components): string
    {
        $resp = Http::withToken($this->token)
            ->timeout(8) // fail fast — a hung WhatsApp Cloud API must not pin a php-fpm worker
            ->acceptJson()
            ->post($this->endpoint(), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $lang],
                    'components' => $components,
                ],
            ]);
        $data = $resp->json();
        if ($resp->ok() && isset($data['messages'][0]['id'])) {
            return (string) $data['messages'][0]['id'];
        }
        throw new \RuntimeException($data['error']['message'] ?? 'WhatsApp send failed');
    }

    /** Login OTP: generate + cache + deliver via the authentication template. */
    public function startVerification($phone_number)
    {
        if (!$this->configured() || empty($this->otpTemplate)) {
            return new Result(['WhatsApp is not configured (token / phone_number_id / otp_template).']);
        }
        $mobile = $this->normalize($phone_number);
        $code = (string) random_int(100000, 999999);
        try {
            $components = [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ];
            // Meta AUTHENTICATION templates ship a copy-code button that also takes the code.
            if ($this->otpHasButton) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $code]],
                ];
            }
            $this->sendTemplate($mobile, $this->otpTemplate, $this->otpLang, $components);
            Cache::put("wa_otp:{$mobile}", $code, now()->addMinutes(5));
            return new Result($mobile);
        } catch (\Throwable $e) {
            return new Result(["WhatsApp OTP send failed: {$e->getMessage()}"]);
        }
    }

    /** Verify the code the user entered against the cached one. */
    public function checkVerification($id, $code, $phone_number)
    {
        $mobile = $this->normalize($phone_number);
        $cached = Cache::get("wa_otp:{$mobile}");
        if ($cached !== null && hash_equals((string) $cached, (string) $code)) {
            Cache::forget("wa_otp:{$mobile}");
            return new Result($id ?: $mobile);
        }
        return new Result(['Invalid or expired code.']);
    }

    /** Notifications: deliver the order-event message via the utility template. */
    public function sendSms($phone_number, $messageBody)
    {
        if (!$this->configured() || empty($this->notifyTemplate)) {
            return new Result(['WhatsApp notify template is not configured.']);
        }
        $mobile = $this->normalize($phone_number);
        try {
            $components = [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => (string) $messageBody]]],
            ];
            $id = $this->sendTemplate($mobile, $this->notifyTemplate, $this->notifyLang, $components);
            return new Result((string) $id);
        } catch (\Throwable $e) {
            return new Result(["WhatsApp notify failed: {$e->getMessage()}"]);
        }
    }
}
