<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Channels;

use App\Modules\Marketing\Application\Channels\ChannelResult;
use App\Modules\Marketing\Application\Channels\ChannelSender;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use Marvel\Otp\Gateways\WhatsappGateway;

/**
 * WhatsApp via the existing WhatsApp Cloud API gateway. Business-initiated
 * marketing messages require a Meta-approved template — the gateway delivers the
 * rendered body as the single {{1}} body variable of WHATSAPP_NOTIFY_TEMPLATE.
 * (A future multi-variable template maps here without touching the send jobs.)
 */
final class WhatsappChannel implements ChannelSender
{
    private const PROVIDER = 'whatsapp';

    public function channel(): string
    {
        return Channel::WHATSAPP;
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
            $result = (new WhatsappGateway())->sendSms($to, (string) $notification->rendered_body);

            if ($result->isValid()) {
                return ChannelResult::sent(self::PROVIDER, $result->getId());
            }

            $errors = $result->getErrors();
            $flat = array_map(fn ($e) => is_scalar($e) ? (string) $e : json_encode($e), $errors);

            return ChannelResult::failed(self::PROVIDER, mb_substr(implode('; ', $flat) ?: 'WhatsApp send failed.', 0, 300));
        } catch (\Throwable $e) {
            return ChannelResult::failed(self::PROVIDER, $e->getMessage());
        }
    }
}
