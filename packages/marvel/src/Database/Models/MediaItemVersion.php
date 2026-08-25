<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot of a MediaItem's actual pixels. `original_key` (and
 * every variant key) is written once and NEVER overwritten — a changed image
 * is a NEW version. Status moves only along TRANSITIONS; publish/retire/
 * rollback are pointer flips on the parent item and touch no S3 object.
 */
class MediaItemVersion extends Model
{
    public const DRAFT = 'draft';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const APPROVED = 'approved';
    public const LIVE = 'live';
    public const REJECTED = 'rejected';
    public const RETIRED = 'retired';

    /** Legal status transitions. Anything absent throws in MediaService. */
    public const TRANSITIONS = [
        self::DRAFT => [self::PROCESSING, self::REJECTED],
        self::PROCESSING => [self::READY, self::REJECTED],
        self::READY => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::LIVE, self::REJECTED],
        self::LIVE => [self::RETIRED],
        // Rollback: a retired version may return to live (pointer flip).
        self::RETIRED => [self::LIVE],
        self::REJECTED => [],
    ];

    /** States whose S3 objects may still be mutated/deleted by their origin env. */
    public const OBJECT_MUTABLE = [self::DRAFT, self::PROCESSING, self::REJECTED];

    protected $table = 'media_item_versions';

    protected $guarded = ['id', 'status'];

    protected $casts = [
        'variants' => 'array',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
        'purged_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Every S3 key this version owns (original + variants). */
    public function allKeys(): array
    {
        $keys = array_values(array_filter((array) $this->variants));
        if ($this->original_key) {
            array_unshift($keys, $this->original_key);
        }
        return array_values(array_unique($keys));
    }
}
