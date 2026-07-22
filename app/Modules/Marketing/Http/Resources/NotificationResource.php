<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;

final class NotificationResource
{
    public static function make(MarketingNotification $n, bool $withBody = false): array
    {
        $data = [
            'uuid'                => $n->uuid,
            'channel'             => $n->channel,
            'recipient'           => $n->recipient,
            'status'              => $n->status,
            'provider'            => $n->provider,
            'provider_message_id' => $n->provider_message_id,
            'retry_count'         => (int) $n->retry_count,
            'error'               => $n->error,
            'rendered_subject'    => $n->rendered_subject,
            'queued_at'           => $n->queued_at?->toIso8601String(),
            'sent_at'             => $n->sent_at?->toIso8601String(),
            'delivered_at'        => $n->delivered_at?->toIso8601String(),
            'read_at'             => $n->read_at?->toIso8601String(),
        ];

        if ($withBody) {
            $data['rendered_body'] = $n->rendered_body;
            $data['recipient_ref'] = $n->recipient_ref ?? [];
        }

        return $data;
    }
}
