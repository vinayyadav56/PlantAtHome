<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\Campaign;

final class CampaignResource
{
    public static function make(Campaign $campaign, bool $withDetails = false): array
    {
        $data = [
            'uuid'                => $campaign->uuid,
            'name'                => $campaign->name,
            'description'         => $campaign->description,
            'status'              => $campaign->status,
            'channels'            => $campaign->channels ?? [],
            'schedule_type'       => $campaign->schedule_type,
            'scheduled_at'        => $campaign->scheduled_at?->toIso8601String(),
            'cron_expression'     => $campaign->cron_expression,
            'timezone'            => $campaign->timezone,
            'batch_size'          => (int) $campaign->batch_size,
            'throttle_per_minute' => $campaign->throttle_per_minute !== null ? (int) $campaign->throttle_per_minute : null,
            'send_delay_seconds'  => (int) $campaign->send_delay_seconds,
            'priority'            => (int) $campaign->priority,
            'max_retries'         => (int) $campaign->max_retries,
            'audience'            => $campaign->audience ? [
                'uuid' => $campaign->audience->uuid,
                'name' => $campaign->audience->name,
            ] : null,
            'audience_version'    => $campaign->audienceVersion ? [
                'uuid'    => $campaign->audienceVersion->uuid,
                'version' => (int) $campaign->audienceVersion->version,
            ] : null,
            'templates'           => $campaign->templates->map(fn ($ct) => [
                'channel'  => $ct->channel,
                'template' => $ct->template ? [
                    'uuid'    => $ct->template->uuid,
                    'name'    => $ct->template->name,
                    'channel' => $ct->template->channel,
                ] : null,
            ])->all(),
            'last_run_at'         => $campaign->last_run_at?->toIso8601String(),
            'next_run_at'         => $campaign->next_run_at?->toIso8601String(),
            'created_by_uuid'     => $campaign->created_by_uuid,
            'created_at'          => $campaign->created_at?->toIso8601String(),
            'updated_at'          => $campaign->updated_at?->toIso8601String(),
        ];

        if ($withDetails) {
            $data['runs'] = $campaign->runs->take(10)->map(fn ($r) => CampaignRunResource::make($r))->values()->all();
        }

        return $data;
    }
}
