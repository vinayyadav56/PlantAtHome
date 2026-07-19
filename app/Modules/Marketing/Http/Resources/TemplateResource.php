<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Resources;

use App\Modules\Marketing\Infrastructure\Models\Template;

final class TemplateResource
{
    public static function make(Template $template): array
    {
        return [
            'uuid'            => $template->uuid,
            'name'            => $template->name,
            'channel'         => $template->channel,
            'description'     => $template->description,
            'status'          => $template->status,
            'current_version' => (int) $template->current_version,
            'content'         => $template->content ?? [],
            'variables'       => $template->variables ?? [],
            'created_by_uuid' => $template->created_by_uuid,
            'created_at'      => $template->created_at?->toIso8601String(),
            'updated_at'      => $template->updated_at?->toIso8601String(),
        ];
    }
}
