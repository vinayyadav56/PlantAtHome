<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\DeliveryLog;

final class DeliveryLogResource
{
    public static function make(DeliveryLog $log): array
    {
        return [
            'uuid'                => $log->uuid,
            'channel'             => $log->channel,
            'status'              => $log->status,
            'provider'            => $log->provider,
            'provider_message_id' => $log->provider_message_id,
            'error'               => $log->error,
            'occurred_at'         => $log->occurred_at?->toIso8601String(),
        ];
    }
}
