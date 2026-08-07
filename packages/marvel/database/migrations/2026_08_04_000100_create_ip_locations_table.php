<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo cache for the logs drawer. Populated by the scheduled `logs:enrich-ips`
 * command (batch lookups over DISTINCT recent IPs) — never in the request path,
 * which is the whole design: geo is a display nicety and must cost the hot path
 * nothing. One row per IP, refreshed only if a lookup ever needs re-running.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ip_locations')) {
            return;
        }
        Schema::create('ip_locations', function (Blueprint $table) {
            $table->string('ip', 64)->primary();
            $table->char('country_code', 2)->nullable();
            $table->string('country', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('city', 64)->nullable();
            $table->string('isp', 191)->nullable();
            $table->timestamp('looked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_locations');
    }
};
