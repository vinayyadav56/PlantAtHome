<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved audience: a named SELECT the operator uses to segment recipients.
 * Its result is frozen into AudienceVersion snapshots (V1, V2 …); campaigns
 * pin a version so the recipient set never shifts under a running campaign.
 */
class Audience extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'marketing_audiences';

    protected $fillable = [
        'uuid', 'name', 'description', 'sql_query', 'status', 'current_version',
        'last_result_count', 'last_execution_ms', 'columns', 'created_by_uuid',
    ];

    protected $casts = [
        'columns'           => 'array',
        'current_version'   => 'integer',
        'last_result_count' => 'integer',
        'last_execution_ms' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(AudienceVersion::class, 'audience_id')->orderByDesc('version');
    }

    public function currentVersion(): HasMany
    {
        return $this->hasMany(AudienceVersion::class, 'audience_id')
            ->where('version', $this->current_version);
    }
}
