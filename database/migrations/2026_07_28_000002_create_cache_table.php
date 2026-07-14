<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel cache table. Enables CACHE_DRIVER=database — a SHARED,
 * PERSISTENT cache store so RateLimiter state survives across requests.
 *
 * Root cause it fixes: staging ran CACHE_DRIVER=array (a per-request store) as a
 * band-aid for an unwritable file-cache dir, which silently defeated the
 * `throttle:auth` limiter (login/refresh brute-force uncapped) AND is not
 * multi-server safe. Database cache works everywhere without a writable disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
