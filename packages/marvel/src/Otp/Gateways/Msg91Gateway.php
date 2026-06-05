<?php

namespace Marvel\Otp\Gateways;

use Illuminate\Support\Facades\Http;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

/**
 * MSG91 OTP gateway (India). REST only — no SDK. MSG91 generates, stores and
 * verifies the OTP server-side against a DLT-approved template, so we just
 * trigger send + verify.
 *
 * Config (config/services.php → 'msg91'): auth_key, template_id, sender, flow_id.
 */
class Msg91Gateway implements OtpInterface
{
    private const BASE = 'https://control.msg91.com/api/v5';

    private string $authKey;
    private ?string $templateId;
    private ?string $sender;
    private ?string $flowId;

    public function __construct()
    {
        $this->authKey = (string) config('services.msg91.auth_key');
        $this->templateId = config('services.msg91.template_id');
        $this->sender = config('services.msg91.sender');
        $this->flowId = config('services.msg91.flow_id');
    }

    /** Normalize to MSG91's expected `91XXXXXXXXXX` (country code, digits only). */
    private function normalize($phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phoneNumber);
        if (strlen($digits) === 10) {
            $digits = '91' . $digits; // bare 10-digit Indian number
        }
        return $digits;
    }

    /** Trigger MSG91 to send an OTP to the number. */
    public function startVerification($phone_number)
    {
        if (empty($this->authKey) || empty($this->templateId)) {
            return new Result(['MSG91 is not configured (auth_key / template_id missing).']);
        }
        $mobile = $this->normalize($phone_number);
        try {
            $resp = Http::withHeaders(['authkey' => $this->authKey])
                ->asForm()
                ->post(self::BASE . '/otp', array_filter([
                    'template_id' => $this->templateId,
                    'mobile' => $mobile,
                    'sender' => $this->sender,
                    'otp_expiry' => 5,
                ]));
            $data = $resp->json();
            if ($resp->ok() && (($data['type'] ?? '') === 'success')) {
                return new Result((string) ($data['request_id'] ?? $mobile));
            }
            return new Result([$data['message'] ?? 'Failed to send OTP.']);
        } catch (\Throwable $e) {
            return new Result(["MSG91 send failed: {$e->getMessage()}"]);
        }
    }

    /** Verify the code the user entered (MSG91 keys verification on mobile + otp). */
    public function checkVerification($id, $code, $phone_number)
    {
        if (empty($this->authKey)) {
            return new Result(['MSG91 is not configured.']);
        }
        $mobile = $this->normalize($phone_number);
        try {
            $resp = Http::withHeaders(['authkey' => $this->authKey])
                ->get(self::BASE . '/otp/verify', [
                    'mobile' => $mobile,
                    'otp' => $code,
                ]);
            $data = $resp->json();
            if ($resp->ok() && (($data['type'] ?? '') === 'success')) {
                return new Result((string) ($id ?: $mobile));
            }
            return new Result([$data['message'] ?? 'Invalid or expired code.']);
        } catch (\Throwable $e) {
            return new Result(["MSG91 verify failed: {$e->getMessage()}"]);
        }
    }

    /** Non-OTP transactional SMS via MSG91 flow (best-effort). */
    public function sendSms($phone_number, $messageBody)
    {
        if (empty($this->authKey) || empty($this->flowId)) {
            return new Result(['MSG91 SMS flow is not configured.']);
        }
        $mobile = $this->normalize($phone_number);
        try {
            $resp = Http::withHeaders(['authkey' => $this->authKey])
                ->post(self::BASE . '/flow/', array_filter([
                    'template_id' => $this->flowId,
                    'sender' => $this->sender,
                    'recipients' => [['mobiles' => $mobile, 'body' => $messageBody]],
                ]));
            $data = $resp->json();
            if ($resp->ok() && (($data['type'] ?? '') === 'success')) {
                return new Result((string) ($data['request_id'] ?? $mobile));
            }
            return new Result([$data['message'] ?? 'Failed to send SMS.']);
        } catch (\Throwable $e) {
            return new Result(["MSG91 SMS failed: {$e->getMessage()}"]);
        }
    }
}
