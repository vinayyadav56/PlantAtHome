<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed translation provider config. `credentials` is cast `encrypted:array`
 * so api keys are encrypted at rest with APP_KEY and never serialized (mirrors
 * CourierPartnerConfig). Exactly one row is `is_active` and drives TranslationManager.
 * Read/written only through the super-admin TranslationProviderController, which masks
 * secrets on read.
 */
class TranslationProviderConfig extends Model
{
    protected $table = 'translation_provider_configs';

    protected $guarded = [];

    protected $casts = [
        'enabled'     => 'boolean',
        'is_active'   => 'boolean',
        'settings'    => 'array',
        'credentials' => 'encrypted:array',
    ];

    // Defense-in-depth: never serialize decrypted credentials.
    protected $hidden = ['credentials'];
}
