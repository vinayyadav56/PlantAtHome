<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Jobs;

/** Drains a batch of email notifications (routes each through the ChannelManager). */
final class SendEmailJob extends AbstractSendBatchJob
{
}
