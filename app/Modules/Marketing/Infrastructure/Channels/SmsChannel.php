<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Channels;

use App\Modules\Marketing\Application\Channels\ChannelResult;
use App\Modules\Marketing\Application\Channels\ChannelSender;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use Marvel\Otp\Gateways\Msg91Gateway;

/**
 * SMS via the existing MSG91 gateway (control.msg91.com Flow API). Requires a
 * DLT-approved MSG91_FLOW_ID — the gateway returns an error Result when unset,
 * which we surface as a failed (retryable) send rather than crashing the batch.
 */
final class SmsChannel implements ChannelSender
{
    private const PROVIDER = 'msg91';

    public function channel(): string
    {
        return Channel::SMS;
    }

    public function send(MarketingNotification $notification): ChannelResult
    {
        if (! config('marketing.dispatch_enabled', true)) {
            return ChannelResult::skipped(self::PROVIDER);
        }

        $to = trim((string) $notification->recipient);
        if ($to === '') {
            return ChannelResult::failed(self::PROVIDER, 'Missing recipient phone number.');
        }

        try {
            $result = (new Msg91Gateway())->sendSms($to, (string) $notification->rendered_body);

            if ($result->isValid()) {
                return ChannelResult::sent(self::PROVIDER, $result->getId());
            }

            return ChannelResult::failed(self::PROVIDER, $this->errorText($result->getErrors()));
        } catch (\Throwable $e) {
            return ChannelResult::failed(self::PROVIDER, $e->getMessage());
        }
    }

    /** @param array<int|string,mixed> $errors */
    private function errorText(array $errors): string
    {
        $flat = array_map(fn ($e) => is_scalar($e) ? (string) $e : json_encode($e), $errors);

        return mb_substr(implode('; ', $flat) ?: 'SMS send failed.', 0, 300);
    }
}
