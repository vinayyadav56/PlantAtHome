<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 Phase 12 (observability): tiny keyed-heartbeat table. The scheduler beats
 * `scheduler` every minute; GET /api/v1/platform/status reports staleness so
 * operators can see a dead cron/worker loop without SSH. DB-backed on purpose:
 * the cache driver is not shared across processes on every environment
 * (staging runs CACHE_DRIVER=array).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_heartbeats', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->timestamp('beat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_heartbeats');
    }
};
