<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 hardening (adversarial-review round):
 *  - Carry an order-level coupon onto the checkout session so a coupon actually
 *    discounts a REAL order (until now it only affected the read-only Quote
 *    preview) and can be redeemed atomically at pay time.
 *  - Make sales_orders.checkout_id UNIQUE so a raced double-pay can create at
 *    most ONE parent order per checkout — a belt-and-braces backstop alongside
 *    the new row lock in CheckoutService::pay().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_checkout_sessions')) {
            Schema::table('sales_checkout_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_checkout_sessions', 'coupon_code')) {
                    $table->string('coupon_code')->nullable()->after('totals');
                }
                if (! Schema::hasColumn('sales_checkout_sessions', 'coupon_discount')) {
                    $table->unsignedBigInteger('coupon_discount')->nullable()->after('coupon_code'); // minor units
                }
            });
        }

        if (Schema::hasTable('sales_orders') && ! $this->hasCheckoutIdUnique()) {
            // Only add the UNIQUE index when the existing data has no duplicate
            // checkout_id — a pre-existing raced order would otherwise block the deploy.
            $dupes = DB::table('sales_orders')
                ->select('checkout_id')->whereNotNull('checkout_id')
                ->groupBy('checkout_id')->havingRaw('COUNT(*) > 1')->exists();

            if (! $dupes) {
                Schema::table('sales_orders', function (Blueprint $table) {
                    $table->unique('checkout_id', 'sales_orders_checkout_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        // Additive hardening — intentionally no-op so a rollback never drops live data.
    }

    private function hasCheckoutIdUnique(): bool
    {
        try {
            return count(DB::select('SHOW INDEX FROM `sales_orders` WHERE Key_name = ?', ['sales_orders_checkout_id_unique'])) > 0;
        } catch (\Throwable $e) {
            return false; // non-MySQL (sqlite tests) — fresh schema, no such index yet
        }
    }
};
