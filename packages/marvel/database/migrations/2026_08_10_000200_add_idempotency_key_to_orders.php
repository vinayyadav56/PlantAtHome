<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout idempotency (enterprise-hardening P0): a client-supplied
 * Idempotency-Key is stored on the PARENT order with a unique index, so a
 * duplicate POST /orders (double-click, network retry, refresh) returns the
 * original order instead of creating a second one — the unique index is the
 * authoritative guard when two requests race past the pre-check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'idempotency_key')) {
                $table->string('idempotency_key', 80)->nullable()->unique()->after('tracking_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'idempotency_key')) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
