<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One translated-field set for a canonical entity in one language.
 *
 * The read-overlay (Marvel\Translation\TranslationContext) bulk-loads these and
 * merges `translated_fields` onto the canonical English model at read time, so
 * there is never a duplicate product/category row.
 */
class TranslationCache extends Model
{
    protected $table = 'translations_cache';

    protected $guarded = [];

    protected $casts = [
        'translated_fields' => 'array',
        'is_reviewed'       => 'boolean',
        'version'           => 'integer',
    ];

    public const STATUS_PENDING    = 'pending';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_OUTDATED   = 'outdated';
    public const STATUS_FAILED     = 'failed';
}
