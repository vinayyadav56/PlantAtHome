<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the shops.mobile and shops.gst_number columns from the legacy
 * settings JSON. The columns were added 2026-07-13 but existing vendors were
 * never migrated — their phone lives at settings->'$.contact' (often with a
 * country-code prefix, e.g. "919996469046") and GST at
 * settings->'$.compliance.gst'. With the columns NULL for every pre-existing
 * vendor, the new uniqueness validation had nothing to match against and
 * duplicate phone/GST registrations sailed through.
 *
 * Idempotent: only touches rows where the target column is still NULL/empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shops') || !Schema::hasColumn('shops', 'mobile')) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // JSON_EXTRACT syntax below is MySQL-specific
        }

        // Phone: strip non-digits, keep the last 10 (drops +91/91/0 prefixes).
        // Done in PHP row-by-row — REGEXP_REPLACE exists on MySQL 8 but chunked
        // PHP keeps this portable and lets us validate length per row.
        DB::table('shops')
            ->whereNull('mobile')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.contact')) IS NOT NULL")
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $settings = json_decode($row->settings ?? '{}', true) ?: [];
                    $digits = preg_replace('/\D+/', '', (string) ($settings['contact'] ?? ''));
                    if (strlen($digits) >= 10) {
                        DB::table('shops')
                            ->where('id', $row->id)
                            ->whereNull('mobile') // re-check: stay idempotent under concurrency
                            ->update(['mobile' => substr($digits, -10)]);
                    }
                }
            });

        // GST: straight copy from settings.compliance.gst (already uppercase
        // by validation). gst_number was only mirrored on saves made after the
        // column existed, so older vendors are NULL.
        if (Schema::hasColumn('shops', 'gst_number')) {
            DB::statement(
                "UPDATE shops
                    SET gst_number = UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.compliance.gst'))))
                  WHERE (gst_number IS NULL OR gst_number = '')
                    AND JSON_UNQUOTE(JSON_EXTRACT(settings, '$.compliance.gst')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(settings, '$.compliance.gst')) != ''"
            );
        }
    }

    public function down(): void
    {
        // Data backfill — nothing sensible to reverse (the JSON source remains).
    }
};
