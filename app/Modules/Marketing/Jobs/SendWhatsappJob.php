<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Jobs;

/** Drains a batch of WhatsApp notifications (routes each through the ChannelManager). */
final class SendWhatsappJob extends AbstractSendBatchJob
{
}
