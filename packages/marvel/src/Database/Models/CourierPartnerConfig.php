<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-partner courier configuration. `credentials` is cast `encrypted:array` so partner secrets
 * (tokens, passwords, service keys) are encrypted at rest with APP_KEY and never stored in plain
 * text. Read/written only through the super-admin CourierConfigController (which masks secrets on
 * read) and resolved by CourierService (DB-first, env fallback).
 */
class CourierPartnerConfig extends Model
{
    protected $table = 'courier_partner_configs';

    protected $guarded = [];

    protected $casts = [
        'enabled'     => 'boolean',
        'settings'    => 'array',
        'credentials' => 'encrypted:array',
    ];

    // Defense-in-depth: never serialize decrypted credentials, even if the model is returned
    // directly somewhere. Reads go through CourierConfigController which masks to booleans.
    protected $hidden = ['credentials'];
}
