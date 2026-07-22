<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Domain\VariableMapper;
use App\Modules\Marketing\Infrastructure\Models\Template;
use App\Modules\Marketing\Infrastructure\Models\TemplateVersion;
use Illuminate\Database\ConnectionInterface;

/**
 * Template Management use-cases (email / SMS / WhatsApp). Content is stored as a
 * channel-shaped JSON blob; the {{variables}} it declares are auto-extracted so
 * the campaign builder can map them to audience columns. Every save freezes a
 * version for history.
 */
final class TemplateService
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function create(array $data, ?string $actorUuid): Template
    {
        $content = $data['content'] ?? [];
        $variables = $this->extractVariables($content);

        return $this->db->transaction(function () use ($data, $content, $variables, $actorUuid) {
            $template = Template::create([
                'name'            => $data['name'],
                'channel'         => $data['channel'],
                'description'     => $data['description'] ?? null,
                'status'          => $data['status'] ?? 'draft',
                'current_version' => 0,
                'content'         => $content,
                'variables'       => $variables,
                'created_by_uuid' => $actorUuid,
            ]);

            $this->freezeVersion($template, $actorUuid);

            return $template->fresh();
        });
    }

    public function update(Template $template, array $data, ?string $actorUuid = null): Template
    {
        return $this->db->transaction(function () use ($template, $data, $actorUuid) {
            $contentChanged = array_key_exists('content', $data);
            $content = $contentChanged ? $data['content'] : $template->content;

            $template->fill(array_filter([
                'name'        => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'status'      => $data['status'] ?? null,
            ], fn ($v) => $v !== null));

            if ($contentChanged) {
                $template->content = $content;
                $template->variables = $this->extractVariables($content);
            }
            $template->save();

            if ($contentChanged) {
                $this->freezeVersion($template, $actorUuid);
            }

            return $template->fresh();
        });
    }

    private function freezeVersion(Template $template, ?string $actorUuid): TemplateVersion
    {
        $nextVersion = (int) $template->current_version + 1;

        $version = TemplateVersion::create([
            'template_id'     => $template->id,
            'version'         => $nextVersion,
            'channel'         => $template->channel,
            'content'         => $template->content,
            'variables'       => $template->variables,
            'created_by_uuid' => $actorUuid,
        ]);

        $template->current_version = $nextVersion;
        $template->save();

        return $version;
    }

    /**
     * Pull {{variables}} out of every string leaf of the content payload,
     * whatever the channel's shape.
     *
     * @param array<string,mixed> $content
     * @return string[]
     */
    private function extractVariables(array $content): array
    {
        $texts = [];
        array_walk_recursive($content, function ($value) use (&$texts) {
            if (is_string($value)) {
                $texts[] = $value;
            }
        });

        return VariableMapper::extract(...$texts);
    }
}
