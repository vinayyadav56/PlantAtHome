<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Replicate LoRA fine-tune (ostris/flux-dev-lora-trainer run). Ready for
 * instant generations once status=succeeded AND replicate_version is set.
 */
class AiLoraModel extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_TRAINING  = 'training';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELED  = 'canceled';

    protected $table = 'ai_lora_models';

    public $guarded = [];

    protected $casts = [
        'settings' => 'json',
    ];

    /** Usable by the instant generator (trained AND we know the version hash). */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED && !empty($this->replicate_version);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
