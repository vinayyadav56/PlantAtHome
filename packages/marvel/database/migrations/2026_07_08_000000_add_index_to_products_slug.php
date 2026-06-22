<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perf — products.slug is filtered per-row by the translated_languages accessor
 * (and slug lookups on PDP). It had no index, causing repeated full scans. Add
 * one. Idempotent (try/catch swallows "index already exists").
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'slug')) {
            return;
        }
        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index('slug');
            });
        } catch (\Throwable $e) {
            // Index already present — safe to ignore on re-run.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['slug']);
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
