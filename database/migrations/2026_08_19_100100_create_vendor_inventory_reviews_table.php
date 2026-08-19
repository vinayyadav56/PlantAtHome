<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for every vendor-inventory review transition. Rows are
 * never updated or deleted — an admin's approval can be superseded, never erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_inventory_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vendor_product_price_id')->index();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('variation_option_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            // submitted | approved | rejected | changes_requested | resubmitted | material_change | migrated
            $table->string('action', 40)->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The columns migration ran first and skipped audit logging — do it now.
        if (Schema::hasColumn('vendor_product_prices', 'review_status')
            && DB::table('vendor_inventory_reviews')->count() === 0) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_inventory_reviews');
    }
};
