<?php

namespace Marvel\Database\Models\Legal\Concerns;

use Illuminate\Support\Str;

/** UUID wire id + uuid route binding (mirrors the v2 HasUuid discipline). */
trait HasLegalUuid
{
    public static function bootHasLegalUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
