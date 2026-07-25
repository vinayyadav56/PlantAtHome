<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One background AI image-generation batch (Excel upload) + its audit trail.
 * Progress counters are mutated only via atomic increments.
 */
class ImageBatch extends Model
{
    public const STATUS_PENDING               = 'pending';
    public const STATUS_PROCESSING            = 'processing';
    public const STATUS_PACKAGING             = 'packaging';
    public const STATUS_COMPLETED             = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED                = 'failed';
    public const STATUS_CANCELLED             = 'cancelled';

    /** Statuses where background work is still running / expected. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_PACKAGING,
    ];

    /** Statuses retry-failed accepts. */
    public const RETRYABLE_STATUSES = [
        self::STATUS_COMPLETED_WITH_ERRORS,
        self::STATUS_FAILED,
    ];

    protected $table = 'image_batches';

    public $guarded = [];

    protected $casts = [
        'reference_images'   => 'json',
        'settings'           => 'json',
        'row_errors'         => 'json',
        'zip_paths'          => 'json',
        'estimated_cost'     => 'float',
        'last_dispatched_at' => 'datetime',
        'cancelled_at'       => 'datetime',
        'packaged_at'        => 'datetime',
        'completed_at'       => 'datetime',
        'expires_at'         => 'datetime',
        'files_pruned_at'    => 'datetime',
    ];

    protected $appends = ['display_id', 'progress'];

    public function getDisplayIdAttribute(): string
    {
        return sprintf('BATCH%06d', $this->id);
    }

    /** 0–100, from counters only (no row scans). */
    public function getProgressAttribute(): int
    {
        $total = (int) $this->total_images;
        if ($total < 1) {
            return in_array($this->status, self::ACTIVE_STATUSES, true) ? 0 : 100;
        }
        $done = (int) $this->generated_count + (int) $this->failed_count + (int) $this->cancelled_count;

        return (int) min(100, floor($done * 100 / $total));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** s3 folder for everything this batch generates (custom output_folder or BATCHxxxxxx). */
    public function storagePrefix(): string
    {
        $folder = $this->settings['output_folder'] ?? null;

        return trim(config('image-batches.s3_prefix', 'ai-batches'), '/') . '/' . ($folder ?: $this->display_id);
    }

    /** 'folders' (per-plant subfolders, default) or 'flat' (all files together). */
    public function fileStructure(): string
    {
        return ($this->settings['file_structure'] ?? 'folders') === 'flat' ? 'flat' : 'folders';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ImageGenerationJob::class, 'batch_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ImageGenerationResult::class, 'batch_id');
    }
}
