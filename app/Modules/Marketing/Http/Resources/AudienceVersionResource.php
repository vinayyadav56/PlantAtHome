<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\AudienceVersion;

final class AudienceVersionResource
{
    public static function make(AudienceVersion $version, bool $withSample = false): array
    {
        $data = [
            'uuid'         => $version->uuid,
            'version'      => (int) $version->version,
            'result_count' => (int) $version->result_count,
            'execution_ms' => $version->execution_ms !== null ? (int) $version->execution_ms : null,
            'truncated'    => (bool) $version->truncated,
            'columns'      => $version->columns ?? [],
            'created_at'   => $version->created_at?->toIso8601String(),
        ];

        if ($withSample) {
            $data['sample'] = $version->sample ?? [];
        }

        return $data;
    }
}
