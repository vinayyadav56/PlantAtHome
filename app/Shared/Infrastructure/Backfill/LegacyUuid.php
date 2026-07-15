<?php

namespace App\Shared\Infrastructure\Backfill;

use Ramsey\Uuid\Uuid;

/**
 * Deterministic UUIDs for rows migrated from the legacy (marvel) tables.
 *
 * UUIDv5(namespace, "<table>:<id>") — the same legacy row always maps to the
 * same v2 uuid, so any module can compute a cross-module reference (e.g. the
 * nursery uuid for shops.id=42) without a lookup table, and re-running a
 * backfill is naturally idempotent.
 */
final class LegacyUuid
{
    /**
     * Project-fixed namespace. Never change this value: every backfilled uuid
     * in every environment derives from it.
     */
    private const NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public static function for(string $table, int|string $id): string
    {
        return Uuid::uuid5(self::NAMESPACE, $table.':'.$id)->toString();
    }
}
