<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\CampaignRun;

final class CampaignRunResource
{
    public static function make(CampaignRun $run): array
    {
        return [
            'uuid'             => $run->uuid,
            'campaign_uuid'    => $run->campaign?->uuid,
            'status'           => $run->status,
            'trigger'          => $run->trigger,
            'total_recipients' => (int) $run->total_recipients,
            'total_messages'   => (int) $run->total_messages,
            'counts'           => $run->counts ?? [],
            'started_at'       => $run->started_at?->toIso8601String(),
            'finished_at'      => $run->finished_at?->toIso8601String(),
            'error'            => $run->error,
            'created_at'       => $run->created_at?->toIso8601String(),
        ];
    }
}
