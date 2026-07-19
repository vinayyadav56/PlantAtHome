<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateVersion extends Model
{
    use HasUuid;

    protected $table = 'marketing_template_versions';

    protected $fillable = [
        'uuid', 'template_id', 'version', 'channel', 'content', 'variables', 'created_by_uuid',
    ];

    protected $casts = [
        'version'   => 'integer',
        'content'   => 'array',
        'variables' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
