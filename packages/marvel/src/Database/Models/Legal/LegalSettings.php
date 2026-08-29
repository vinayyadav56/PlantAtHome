<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;

/** Singleton row (seeded by the migration). */
class LegalSettings extends Model
{
    protected $table = 'legal_settings';
    protected $guarded = ['id'];
    protected $casts = ['settings' => 'array'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], []);
    }
}
