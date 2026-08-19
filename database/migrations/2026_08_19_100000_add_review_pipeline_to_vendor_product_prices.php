<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin review pipeline over vendor inventory: no vendor row is customer-visible
 * until review_status = approved (enforced in AvailabilityService / PricingService /
 * DeliveryOptions — the only customer-facing readers of this table).
 *
 * Backfill policy (owner decision): rows a VENDOR created (source='vendor') enter the
 * review queue as pending; rows admins created (price-sheet imports, manual, seeds)
 * are grandfathered as approved — they ARE the admin-vetted city catalog, and putting
 * them in the queue would blank production's priced catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            $table->string('review_status', 32)->default('pending_review')->index();
            $table->text('review_comment')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
        });

        $now = now();
        DB::table('vendor_product_prices')
            ->where('source', 'vendor')
            ->update(['review_status' => 'pending_review', 'submitted_at' => DB::raw('created_at')]);
        DB::table('vendor_product_prices')
            ->where(fn ($q) => $q->where('source', '!=', 'vendor')->orWhereNull('source'))
            ->update(['review_status' => 'approved', 'approved_at' => $now]);

        // One migrated audit row per existing row, if the audit table exists yet
        // (ordering: this migration may run before the audit-table one on a fresh DB —
        // the audit migration re-runs this backfill logging when the table is created).
        if (Schema::hasTable('vendor_inventory_reviews')) {
            $this->logMigrated();
        }
    }

    public function logMigrated(): void
    {
        $rows = DB::table('vendor_product_prices')->get(['id', 'shop_id', 'product_id', 'variation_option_id', 'review_status']);
        $now = now();
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('vendor_inventory_reviews')->insert($chunk->map(fn ($r) => [
                'vendor_product_price_id' => $r->id,
                'shop_id'                 => $r->shop_id,
                'product_id'              => $r->product_id,
                'variation_option_id'     => $r->variation_option_id,
                'previous_status'         => null,
                'new_status'              => $r->review_status,
                'action'                  => 'migrated',
                'actor_user_id'           => null,
                'comment'                 => 'Backfilled by the review-pipeline migration',
                'created_at'              => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'review_comment', 'reviewed_by_user_id', 'reviewed_at', 'submitted_at', 'approved_at']);
        });
    }
};
