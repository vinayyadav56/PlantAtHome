<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-row metrics for the "generate missing plant content" run: how many fields
 * were missing / generated, token usage and AI latency. Additive + guarded; the
 * run summary is aggregated from these rows, never from incremented counters.
 */
return new class extends Migration
{
    private const COLUMNS = ['fields_missing', 'fields_generated', 'prompt_tokens', 'completion_tokens', 'ai_ms'];

    public function up(): void
    {
        if (!Schema::hasTable('product_content_jobs')) {
            return;
        }

        Schema::table('product_content_jobs', function (Blueprint $table) {
            $add = [
                'fields_missing'    => fn () => $table->unsignedSmallInteger('fields_missing')->default(0),
                'fields_generated'  => fn () => $table->unsignedSmallInteger('fields_generated')->default(0),
                'prompt_tokens'     => fn () => $table->unsignedInteger('prompt_tokens')->default(0),
                'completion_tokens' => fn () => $table->unsignedInteger('completion_tokens')->default(0),
                'ai_ms'             => fn () => $table->unsignedInteger('ai_ms')->default(0),
            ];
            foreach ($add as $col => $make) {
                if (!Schema::hasColumn('product_content_jobs', $col)) {
                    $make();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_content_jobs')) {
            return;
        }

        Schema::table('product_content_jobs', function (Blueprint $table) {
            foreach (self::COLUMNS as $col) {
                if (Schema::hasColumn('product_content_jobs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
