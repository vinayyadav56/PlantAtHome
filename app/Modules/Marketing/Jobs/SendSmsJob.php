<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Jobs;

/** Drains a batch of SMS notifications (routes each through the ChannelManager). */
final class SendSmsJob extends AbstractSendBatchJob
{
}
