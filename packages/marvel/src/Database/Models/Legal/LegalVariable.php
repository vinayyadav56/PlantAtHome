<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;

/**
 * A configurable operational value referenced by documents as {{key}}.
 *
 * Seeded values are DRAFTING DEFAULTS, not decisions: is_approved stays false
 * until management signs the number off, and an unapproved variable blocks
 * publication of any public-facing document that uses it.
 */
class LegalVariable extends Model
{
    protected $table = 'legal_variables';
    protected $guarded = ['id'];
    protected $casts = ['is_approved' => 'bool', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updated(function (self $v) {
            if (array_key_exists('is_approved', $v->getChanges())) {
                LegalAudit::record(
                    $v->is_approved ? 'variable_approved' : 'variable_unapproved',
                    null, null,
                    ['key' => $v->key, 'value' => $v->value],
                );
            } elseif (array_key_exists('value', $v->getChanges())) {
                LegalAudit::record('variable_changed', null, null, [
                    'key' => $v->key,
                    'from' => $v->getOriginal('value'),
                    'to' => $v->value,
                ]);
            }
        });
    }
}
