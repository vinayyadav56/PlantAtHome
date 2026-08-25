<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Binds a MediaItem to an entity with a role (main|gallery|banner|logo|cover)
 * and a display position. Ordering lives HERE — reordering never creates a
 * media version.
 */
class MediaAttachment extends Model
{
    protected $table = 'media_attachments';

    protected $guarded = ['id'];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
