<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Channels;

use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;

/**
 * The notification dispatcher: resolves the right ChannelSender for a
 * notification's channel and delivers it. This is the single seam the spec's
 * "NotificationInterface … replaceable through DI" calls for — bind a different
 * set of senders (or a faked one in tests) in the service provider and every
 * send job routes through the new implementation unchanged. Adding push / in-app
 * / voice / webhook later is: implement ChannelSender, register it here.
 */
final class ChannelManager
{
    /** @var array<string, ChannelSender> */
    private array $senders = [];

    /** @param iterable<ChannelSender> $senders */
    public function __construct(iterable $senders = [])
    {
        foreach ($senders as $sender) {
            $this->register($sender);
        }
    }

    public function register(ChannelSender $sender): void
    {
        $this->senders[$sender->channel()] = $sender;
    }

    public function supports(string $channel): bool
    {
        return isset($this->senders[$channel]);
    }

    /** @return string[] the channels currently wired */
    public function channels(): array
    {
        return array_keys($this->senders);
    }

    public function send(MarketingNotification $notification): ChannelResult
    {
        $sender = $this->senders[$notification->channel] ?? null;

        if (! $sender) {
            return ChannelResult::failed('none', "No sender registered for channel '{$notification->channel}'.");
        }

        return $sender->send($notification);
    }
}
