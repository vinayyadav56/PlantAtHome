<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advisory registry of records explicitly marked as test data.
 *
 * The schema carries no provenance column on users/shops/orders, so marks live in one side
 * table rather than six new columns on hot tables. Marks INFORM the operator ("12 of these 23
 * are marked test") and can scope a cleanup — but they never authorise deletion by themselves;
 * scope + preview + confirmation do. That matters because everything created before marking
 * existed carries no mark, and the pre-launch reset must still be able to reach it.
 */
class TestDataMarker
{
    public static function mark(string $type, int $id, ?string $reason = null, ?int $by = null): void
    {
        if (!Schema::hasTable('test_data_marks')) {
            return;
        }
        DB::table('test_data_marks')->updateOrInsert(
            ['markable_type' => $type, 'markable_id' => $id],
            ['reason' => $reason, 'marked_by' => $by, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public static function idsFor(string $type): array
    {
        if (!Schema::hasTable('test_data_marks')) {
            return [];
        }
        return DB::table('test_data_marks')->where('markable_type', $type)->pluck('markable_id')->all();
    }

    public static function countFor(string $type): int
    {
        return count(self::idsFor($type));
    }
}
