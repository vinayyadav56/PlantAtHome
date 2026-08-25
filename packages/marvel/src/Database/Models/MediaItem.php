<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The stable identity of one image/asset. The uuid survives environment DB
 * refreshes (staging inherits prod's uuids), numeric ids do not — never use
 * the id in an S3 key or a cross-environment reference.
 */
class MediaItem extends Model
{
    protected $table = 'media_items';

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid = $item->uuid ?: (string) Str::uuid();
            $item->origin_env = $item->origin_env ?: config('media.env');
        });
    }

    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(MediaItemVersion::class, 'live_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MediaItemVersion::class)->orderBy('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version_number') + 1;
    }
}
