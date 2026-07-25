<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product's slot in a bulk AI content run (atomically claimed). */
class ProductContentJob extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RETRYING   = 'retrying';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_SKIPPED    = 'skipped';
    public const STATUS_FAILED     = 'failed';

    /** A worker may claim a row only from these statuses. */
    public const CLAIMABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RETRYING,
    ];

    protected $table = 'product_content_jobs';

    public $guarded = [];

    protected $casts = [
        'changes'    => 'json',
        'claimed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductContentBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
