<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deduped per-string translations. Identical source phrases are translated once
 * per language and reused forever — the core AI-cost optimisation.
 */
class TranslationStringCache extends Model
{
    protected $table = 'translation_string_cache';

    protected $guarded = [];

    /** Stable key for a (language, source) pair. */
    public static function hashFor(string $language, string $source): string
    {
        return hash('sha256', $language . '|' . $source);
    }
}
