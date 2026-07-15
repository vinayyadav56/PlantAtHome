<?php

namespace App\Modules\Nursery\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KYC/compliance document attached to a nursery (gstin, pan, license, …).
 * Reviewed by an admin; status gates approval workflows.
 */
class NurseryDocument extends Model
{
    use HasUuid;

    protected $table = 'nursery_documents';

    protected $fillable = [
        'uuid', 'nursery_id', 'kind', 'file', 'status', 'note',
        'reviewed_by_uuid', 'reviewed_at',
    ];

    protected $casts = [
        'file'        => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function nursery(): BelongsTo
    {
        return $this->belongsTo(Nursery::class, 'nursery_id');
    }
}
