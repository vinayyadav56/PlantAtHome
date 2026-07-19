<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\Audience;

final class AudienceResource
{
    public static function make(Audience $audience, bool $withVersions = false): array
    {
        $data = [
            'uuid'              => $audience->uuid,
            'name'              => $audience->name,
            'description'       => $audience->description,
            'sql_query'         => $audience->sql_query,
            'status'            => $audience->status,
            'current_version'   => (int) $audience->current_version,
            'last_result_count' => $audience->last_result_count !== null ? (int) $audience->last_result_count : null,
            'last_execution_ms' => $audience->last_execution_ms !== null ? (int) $audience->last_execution_ms : null,
            'columns'           => $audience->columns ?? [],
            'created_by_uuid'   => $audience->created_by_uuid,
            'created_at'        => $audience->created_at?->toIso8601String(),
            'updated_at'        => $audience->updated_at?->toIso8601String(),
        ];

        if ($withVersions) {
            $data['versions'] = $audience->versions->map(fn ($v) => AudienceVersionResource::make($v))->all();
        }

        return $data;
    }
}
