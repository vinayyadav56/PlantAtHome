<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Support;

use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Jobs\SendEmailJob;
use App\Modules\Marketing\Jobs\SendSmsJob;
use App\Modules\Marketing\Jobs\SendWhatsappJob;

/** Single source of truth mapping a channel to the Send*Job that drains it. */
final class SendJobRouter
{
    private const MAP = [
        Channel::EMAIL    => SendEmailJob::class,
        Channel::SMS      => SendSmsJob::class,
        Channel::WHATSAPP => SendWhatsappJob::class,
    ];

    /** @return class-string|null */
    public static function jobFor(string $channel): ?string
    {
        return self::MAP[$channel] ?? null;
    }
}
