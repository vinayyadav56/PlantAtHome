<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Channels;

use App\Modules\Marketing\Application\Channels\ChannelResult;
use App\Modules\Marketing\Application\Channels\ChannelSender;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Email via the app's SendGrid mailer. Prefers the HTTPS v3 API mailer when a
 * key is configured (Railway blackholes SMTP) and otherwise falls back to the
 * default mailer. Sends the rendered HTML; derives a plain-text alternative.
 */
final class EmailChannel implements ChannelSender
{
    private const PROVIDER = 'sendgrid';

    public function channel(): string
    {
        return Channel::EMAIL;
    }

    public function send(MarketingNotification $notification): ChannelResult
    {
        if (! config('marketing.dispatch_enabled', true)) {
            return ChannelResult::skipped(self::PROVIDER);
        }

        $to = trim((string) $notification->recipient);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ChannelResult::failed(self::PROVIDER, 'Invalid recipient email address.');
        }

        $html = (string) $notification->rendered_body;
        $subject = trim((string) $notification->rendered_subject);
        if ($subject === '') {
            $subject = (string) config('app.name', 'PlantAtHome');
        }

        $mailer = config('mail.mailers.sendgrid.key') ? 'sendgrid' : config('mail.default');

        try {
            Mail::mailer($mailer)->html($html, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });

            return ChannelResult::sent(self::PROVIDER);
        } catch (\Throwable $e) {
            return ChannelResult::failed(self::PROVIDER, $e->getMessage());
        }
    }
}
