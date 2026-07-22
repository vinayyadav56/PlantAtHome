<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A channel-specific message template. `content` is a JSON payload whose shape
 * depends on channel (email: subject/html/text/header/footer/buttons; sms:
 * body/unicode; whatsapp: template_name/header/body/footer/buttons/media).
 * `variables` are the extracted {{tokens}} used for auto-mapping to audiences.
 */
class Template extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'marketing_templates';

    protected $fillable = [
        'uuid', 'name', 'channel', 'description', 'status', 'current_version',
        'content', 'variables', 'created_by_uuid',
    ];

    protected $casts = [
        'content'         => 'array',
        'variables'       => 'array',
        'current_version' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'template_id')->orderByDesc('version');
    }
}
