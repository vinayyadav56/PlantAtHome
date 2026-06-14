<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event earnings ledger for a delivery partner — one row per credit event so
 * earnings can be grouped by day/week/month/quarter/6-month/custom and split into
 * commission vs incentive. The aggregate `delivery_partner_balances` row stays the
 * running total; this table is the history/breakup. Backfills commission rows from
 * already-completed orders so existing earnings appear in the analytics.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('delivery_partner_earnings')) {
            Schema::create('delivery_partner_earnings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delivery_partner_id')->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('type')->default('commission');   // commission | incentive
                $table->string('source')->default('delivery');   // delivery | manual | rule:<key>
                $table->decimal('amount', 14, 2)->default(0);     // negative = reversal
                $table->text('note')->nullable();
                $table->timestamp('earned_at')->nullable()->index();
                $table->timestamps();

                $table->index(['delivery_partner_id', 'earned_at']);
                $table->index(['delivery_partner_id', 'type']);
            });
        }

        // Idempotent backfill: one commission row per completed order that already
        // credited a DP (skip if a row for that order already exists).
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'dp_commission_amount')) {
            $orders = DB::table('orders')
                ->whereNotNull('delivery_partner_id')
                ->where('order_status', 'order-completed')
                ->where('dp_commission_amount', '>', 0)
                ->get(['id', 'delivery_partner_id', 'dp_commission_amount', 'updated_at', 'created_at']);

            foreach ($orders as $o) {
                $exists = DB::table('delivery_partner_earnings')
                    ->where('order_id', $o->id)->where('type', 'commission')->exists();
                if ($exists) {
                    continue;
                }
                DB::table('delivery_partner_earnings')->insert([
                    'delivery_partner_id' => $o->delivery_partner_id,
                    'order_id'            => $o->id,
                    'type'                => 'commission',
                    'source'              => 'delivery',
                    'amount'              => $o->dp_commission_amount,
                    'note'                => 'Backfilled from completed order',
                    'earned_at'           => $o->updated_at ?? $o->created_at,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_partner_earnings');
    }
};
