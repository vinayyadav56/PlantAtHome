<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

/**
 * The delivery channels a campaign can use. New channels (push, in_app, voice,
 * webhook) are added here + a matching ChannelSender registered in the
 * ChannelManager — no other code changes needed, per the extensibility goal.
 */
final class Channel
{
    public const EMAIL    = 'email';
    public const SMS      = 'sms';
    public const WHATSAPP = 'whatsapp';

    /** @return string[] */
    public static function all(): array
    {
        return [self::EMAIL, self::SMS, self::WHATSAPP];
    }

    public static function isValid(string $channel): bool
    {
        return in_array($channel, self::all(), true);
    }

    /** email → recipient is an address; sms/whatsapp → a phone number. */
    public static function recipientField(string $channel): string
    {
        return $channel === self::EMAIL ? 'email' : 'phone';
    }
}
