<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated (or failed) image slot. The auto-increment id is the
 * globally unique internal Image ID; the s3 object keeps the clean
 * plant-facing filename.
 */
class ImageGenerationResult extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $table = 'image_generation_results';

    public $guarded = [];

    protected $appends = ['display_id'];

    public function getDisplayIdAttribute(): string
    {
        return sprintf('IMG%012d', $this->id);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ImageGenerationJob::class, 'job_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImageBatch::class, 'batch_id');
    }
}
