<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Excel plant row inside an image batch. */
class ImageGenerationJob extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_RETRYING   = 'retrying';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_PARTIAL    = 'partial';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    /** Statuses a worker may claim. */
    public const CLAIMABLE_STATUSES = [self::STATUS_PENDING, self::STATUS_RETRYING];

    /** Statuses retry-failed resets. */
    public const RETRYABLE_STATUSES = [self::STATUS_FAILED, self::STATUS_PARTIAL];

    protected $table = 'image_generation_jobs';

    public $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImageBatch::class, 'batch_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ImageGenerationResult::class, 'job_id');
    }
}
