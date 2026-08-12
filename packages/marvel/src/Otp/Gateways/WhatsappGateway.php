<?php

namespace Marvel\Otp\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    private int $otpTtlMinutes;
    private int $otpMaxAttempts;

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
        $this->otpTtlMinutes = max(1, (int) ($cfg['otp_ttl_minutes'] ?? 5));
        $this->otpMaxAttempts = max(1, (int) ($cfg['otp_max_attempts'] ?? 5));
    }

    /** Seconds a freshly issued code stays valid (the client shows this countdown). */
    public function ttlSeconds(): int
    {
        return $this->otpTtlMinutes * 60;
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

    /** Cache key for the pending code of a normalized number. */
    private function otpKey(string $mobile): string
    {
        return "wa_otp:{$mobile}";
    }

    /**
     * Login OTP: generate + store (HASHED) + deliver via the authentication template.
     *
     * The code is never persisted, logged or returned in plaintext — only a bcrypt
     * hash lives in the cache, mirroring how email_otps stores its codes. Issuing a
     * new code OVERWRITES the pending one, so a resend invalidates its predecessor.
     */
    public function startVerification($phone_number)
    {
        if (!$this->configured() || empty($this->otpTemplate)) {
            return new Result(['WhatsApp is not configured (token / phone_number_id / otp_template).']);
        }
        $mobile = $this->normalize($phone_number);
        $code = (string) random_int(100000, 999999); // CSPRNG
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
            $messageId = $this->sendTemplate($mobile, $this->otpTemplate, $this->otpLang, $components);
            Cache::put($this->otpKey($mobile), [
                'hash' => Hash::make($code),
                'attempts' => 0,
                'message_id' => $messageId,
                // Absolute deadline so a wrong guess can be re-stored WITHOUT
                // extending the window (a re-put with a fresh TTL would).
                'expires_at' => now()->addMinutes($this->otpTtlMinutes)->getTimestamp(),
            ], now()->addMinutes($this->otpTtlMinutes));
            // Observability: the message id correlates our send with Meta's delivery
            // webhook. The CODE is never logged — only the last 4 digits of the number.
            Log::info('otp.whatsapp.dispatched', [
                'phone_suffix' => substr($mobile, -4),
                'message_id' => $messageId,
                'template' => $this->otpTemplate,
                'ttl_seconds' => $this->ttlSeconds(),
            ]);
            return new Result($mobile);
        } catch (\Throwable $e) {
            Log::warning('otp.whatsapp.dispatch_failed', [
                'phone_suffix' => substr($mobile, -4),
                'template' => $this->otpTemplate,
                'error' => $e->getMessage(), // Meta's message; never the token or the code
            ]);
            return new Result(["WhatsApp OTP send failed: {$e->getMessage()}"]);
        }
    }

    /**
     * Verify the entered code against the stored hash. Single-use (the entry is
     * dropped on success) and attempt-capped: the entry counts wrong tries and
     * self-destructs at the cap, so a burnt code cannot be brute-forced even
     * within its TTL. (OtpAbuseGuard independently locks the NUMBER out.)
     */
    public function checkVerification($id, $code, $phone_number)
    {
        $mobile = $this->normalize($phone_number);
        $key = $this->otpKey($mobile);
        $entry = Cache::get($key);

        // Legacy plaintext entries (issued before hashing shipped) stay verifiable
        // for their remaining TTL so codes already in flight are not invalidated.
        if (is_string($entry)) {
            if (hash_equals($entry, (string) $code)) {
                Cache::forget($key);
                return new Result($id ?: $mobile);
            }
            return new Result(['Invalid or expired code.']);
        }

        if (!is_array($entry) || empty($entry['hash'])) {
            return new Result(['Invalid or expired code.']);
        }
        if (Hash::check((string) $code, (string) $entry['hash'])) {
            Cache::forget($key); // single-use
            return new Result($id ?: $mobile);
        }

        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
        // Re-store against the ORIGINAL deadline — a wrong guess must never
        // extend the code's life.
        $remaining = (int) ($entry['expires_at'] ?? 0) - now()->getTimestamp();
        if ($entry['attempts'] >= $this->otpMaxAttempts || $remaining <= 0) {
            Cache::forget($key); // burn the code; the user must request a new one
        } else {
            Cache::put($key, $entry, now()->addSeconds($remaining));
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
