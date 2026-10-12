<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product's slot in a bulk AI content run (atomically claimed). */
class ProductContentJob extends Model
{
    public const STATUS_PENDING      = 'pending';
    public const STATUS_PROCESSING   = 'processing';
    public const STATUS_RETRYING     = 'retrying';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_SKIPPED      = 'skipped';
    public const STATUS_FAILED       = 'failed';
    /** Missing-content mode: only declined/flagged fields remain — a human fills them in. */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /** A worker may claim a row only from these statuses. */
    public const CLAIMABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RETRYING,
    ];

    /** Rows the run still owes work on (everything else is finished). */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RETRYING,
        self::STATUS_PROCESSING,
    ];

    protected $table = 'product_content_jobs';

    public $guarded = [];

    protected $casts = [
        'changes'           => 'json',
        'claimed_at'        => 'datetime',
        'fields_missing'    => 'integer',
        'fields_generated'  => 'integer',
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'ai_ms'             => 'integer',
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
