<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill first_name/last_name from the existing single `name` column by
 * splitting on the first space (remainder → last_name). Chunked PHP so it
 * runs identically on MySQL (prod) and sqlite (tests); idempotent — only
 * rows with a NULL first_name are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'first_name')) {
            return;
        }

        DB::table('users')
            ->select(['id', 'name'])
            ->whereNull('first_name')
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $name = trim((string) $user->name);
                    if ($name === '') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $name, 2);
                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => mb_substr($parts[0], 0, 120),
                        'last_name'  => isset($parts[1]) && trim($parts[1]) !== '' ? mb_substr(trim($parts[1]), 0, 120) : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Backfill only — nothing to undo (columns are dropped by their own migration).
    }
};
