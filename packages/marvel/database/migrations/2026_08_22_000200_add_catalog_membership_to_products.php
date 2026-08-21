<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The curated layer between "the catalogue contains it" and "the website may sell it".
 *
 * Until now those were the same thing: every row in `products` was a candidate for listing, for
 * vendor attachment, for bundles and for inventory. That is why the admin list shows ~2,600
 * bulk-imported competitor names beside real products, and why nothing could be staged before it
 * reached customers. `is_available_product` is the Master Catalog membership an admin grants by an
 * explicit act; `listing_enabled` is the separate on/off switch inside it, so publishing is a
 * SECOND deliberate act rather than a side effect of curating.
 *
 * Deliberately NOT a new `status` value. `products.status` is a MySQL enum materialised from
 * ProductStatus::getValues() at migration time, so extending that vocabulary means altering the
 * column — the same reason `review_note` became a side column one migration ago. Membership is
 * also orthogonal to status: a product can be `publish` and still be held out of the catalogue.
 *
 * Deliberately NOT a membership table. A defaulted column is what makes "Available Products starts
 * empty" true by construction rather than by a reset script: nothing backfills these, so both
 * environments begin at zero, and the Railway boot sequence — which re-runs PlantAtHomePlantBulk,
 * Tools, Product, assign-shop, categorize-plants and apply-size-pricing on EVERY boot — cannot
 * flip them on, because every one of those seeders uses updateOrCreate with a fixed column list.
 *
 * `track_stock` mirrors vendor_product_prices.track_stock: FALSE means unlimited. It gives the
 * catalogue an "Unlimited by default" inventory without making products.quantity nullable, which
 * would ripple through the atomic decrement in ProductInventoryDecrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_available_product')) {
                $table->boolean('is_available_product')->default(false)->index()->after('status');
            }
            if (!Schema::hasColumn('products', 'listing_enabled')) {
                $table->boolean('listing_enabled')->default(false)->index()->after('is_available_product');
            }
            if (!Schema::hasColumn('products', 'available_at')) {
                $table->timestamp('available_at')->nullable()->after('listing_enabled');
            }
            if (!Schema::hasColumn('products', 'available_by')) {
                $table->unsignedBigInteger('available_by')->nullable()->after('available_at');
            }
            if (!Schema::hasColumn('products', 'track_stock')) {
                $table->boolean('track_stock')->default(false)->after('quantity');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['is_available_product', 'listing_enabled', 'available_at', 'available_by', 'track_stock'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
