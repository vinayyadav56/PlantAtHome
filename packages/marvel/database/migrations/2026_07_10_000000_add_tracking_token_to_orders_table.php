<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order secret token (the "complete fix" for guest-order enumeration).
 *
 * Guest orders have no customer_id, so historically anyone who guessed/iterated a
 * tracking_number could read the order (an IDOR). We now mint a high-entropy
 * `tracking_token` on every new order and require it to view a GUEST order. The
 * post-checkout redirect + the order-confirmation email carry the token, so the
 * legitimate buyer is unaffected; an enumerating attacker gets a 404.
 *
 * Backward compatible: legacy guest orders (token = NULL) keep the existing
 * PII-stripped public fallback so old emailed links don't break. Registered
 * orders are unaffected (they're guarded by the owner check, now returning 404
 * instead of 403 so existence isn't disclosed). Additive + nullable + indexed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->index()->after('tracking_number');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'tracking_token')) {
                $table->dropColumn('tracking_token');
            }
        });
    }
};
