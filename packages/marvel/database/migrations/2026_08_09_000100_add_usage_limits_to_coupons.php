<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage-limited / single-use coupons.
 *
 * Marvel coupons were unlimited-use discount codes — no cap, no per-customer limit, no
 * redemption record. This adds an OPT-IN cap (both columns null = the old unlimited
 * behaviour, so nothing changes for existing coupons) plus the `coupon_usages` ledger that
 * makes redemption concurrency-safe and idempotent:
 *
 *   - coupons.usage_limit            total redemptions allowed (null = unlimited)
 *   - coupons.usage_limit_per_user   redemptions allowed per customer (null = unlimited)
 *   - coupons.times_used             running counter, incremented under a row lock
 *   - coupon_usages                  one row per (coupon, order); UNIQUE(coupon_id, order_id)
 *                                    is the idempotency key — a retried/duplicated checkout
 *                                    for the same order can never consume the coupon twice.
 *
 * Additive + nullable; safe to run on the live table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('coupons')) {
            Schema::table('coupons', function (Blueprint $table) {
                if (!Schema::hasColumn('coupons', 'usage_limit')) {
                    $table->unsignedInteger('usage_limit')->nullable()->after('expire_at');
                }
                if (!Schema::hasColumn('coupons', 'usage_limit_per_user')) {
                    $table->unsignedInteger('usage_limit_per_user')->nullable()->after('usage_limit');
                }
                if (!Schema::hasColumn('coupons', 'times_used')) {
                    $table->unsignedInteger('times_used')->default(0)->after('usage_limit_per_user');
                }
            });
        }

        if (!Schema::hasTable('coupon_usages')) {
            Schema::create('coupon_usages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('coupon_id');
                $table->unsignedBigInteger('user_id')->nullable(); // guest checkout has no user
                $table->unsignedBigInteger('order_id');
                $table->timestamps();

                // Idempotency: the same order can hold at most one usage row for a coupon.
                $table->unique(['coupon_id', 'order_id'], 'coupon_usages_coupon_order_uq');
                // Per-user counting.
                $table->index(['coupon_id', 'user_id'], 'coupon_usages_coupon_user_ix');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        if (Schema::hasTable('coupons')) {
            Schema::table('coupons', function (Blueprint $table) {
                foreach (['usage_limit', 'usage_limit_per_user', 'times_used'] as $c) {
                    if (Schema::hasColumn('coupons', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
