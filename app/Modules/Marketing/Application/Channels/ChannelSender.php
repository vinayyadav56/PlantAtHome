<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Channels;

use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;

/**
 * One outbound channel (email / sms / whatsapp / …). Implementations are the
 * swappable providers — bind a different ChannelSender in the container to
 * change or fake how a channel delivers, with no change to the send jobs. A new
 * channel is just a new implementation registered in the ChannelManager.
 */
interface ChannelSender
{
    /** The channel key this sender handles (Channel::EMAIL, …). */
    public function channel(): string;

    /** Deliver one materialized notification and report the provider outcome. */
    public function send(MarketingNotification $notification): ChannelResult;
}
