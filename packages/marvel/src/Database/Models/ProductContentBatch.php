<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bulk AI product-content generation run (from the admin product listing)
 * + its audit trail. Progress counters are mutated only via atomic increments.
 */
class ProductContentBatch extends Model
{
    public const STATUS_PENDING               = 'pending';
    public const STATUS_PROCESSING            = 'processing';
    public const STATUS_COMPLETED             = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED                = 'failed';
    public const STATUS_CANCELLED             = 'cancelled';

    /** Statuses where background work is still running / expected. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
    ];

    /** Statuses retry-failed accepts. */
    public const RETRYABLE_STATUSES = [
        self::STATUS_COMPLETED_WITH_ERRORS,
        self::STATUS_FAILED,
    ];

    protected $table = 'product_content_batches';

    public $guarded = [];

    protected $casts = [
        'options'            => 'json',
        'row_errors'         => 'json',
        'estimated_cost'     => 'float',
        'last_dispatched_at' => 'datetime',
        'cancelled_at'       => 'datetime',
        'completed_at'       => 'datetime',
    ];

    protected $appends = ['display_id', 'progress'];

    public function getDisplayIdAttribute(): string
    {
        return sprintf('CONTENT%06d', $this->id);
    }

    /** 0–100, from counters only (no row scans). */
    public function getProgressAttribute(): int
    {
        $total = (int) $this->total_rows;
        if ($total < 1) {
            return in_array($this->status, self::ACTIVE_STATUSES, true) ? 0 : 100;
        }

        return (int) min(100, floor((int) $this->processed_count * 100 / $total));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ProductContentJob::class, 'batch_id');
    }
}
