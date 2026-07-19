<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable frozen snapshot of an audience at refresh time. `snapshot` holds
 * the result rows as JSON (capped at marketing.audience.max_rows; truncated
 * flags an over-cap result). Campaigns materialize recipients from this.
 */
class AudienceVersion extends Model
{
    use HasUuid;

    protected $table = 'marketing_audience_versions';

    protected $fillable = [
        'uuid', 'audience_id', 'version', 'sql_query', 'snapshot', 'sample',
        'columns', 'result_count', 'execution_ms', 'truncated', 'created_by_uuid',
    ];

    // The frozen rows can be many MB — never serialize them into an API response
    // by accident (they're read explicitly via rows() only during materialization).
    protected $hidden = ['snapshot'];

    protected $casts = [
        'version'      => 'integer',
        'sample'       => 'array',
        'columns'      => 'array',
        'result_count' => 'integer',
        'execution_ms' => 'integer',
        'truncated'    => 'boolean',
    ];

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class, 'audience_id');
    }

    /**
     * Decode the frozen rows on demand (never eager — can be large). Returns a
     * plain array of associative rows.
     *
     * @return array<int, array<string,mixed>>
     */
    public function rows(): array
    {
        if (! $this->snapshot) {
            return [];
        }
        $decoded = json_decode($this->snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }
}
