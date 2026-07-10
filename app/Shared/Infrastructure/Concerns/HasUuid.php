<?php

namespace App\Shared\Infrastructure\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a stable UUID on insert and lets models be resolved by it. UUIDs are
 * the only identifier exposed over the wire (Section 11); the auto-increment id
 * stays internal. Applied by every v2 aggregate-root model.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** Route-model binding by uuid, never by internal id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeByUuid($query, string $uuid)
    {
        return $query->where($this->qualifyColumn('uuid'), $uuid);
    }
}
