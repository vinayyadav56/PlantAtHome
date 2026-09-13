<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST/tax infrastructure — additive & backward-compatible.
 *
 * - tax_classes becomes reusable GST rate configs (HSN + category + effective
 *   window + active flag). `rate` stays the GST %; the existing flat-rate class
 *   keeps working unchanged.
 * - products carry HSN + a tax_rate_id (→ tax_classes) + a per-product inclusive
 *   override + a `tax_verified` flag. Existing products default to unverified,
 *   0% (never a blind 18%).
 * - orders + order_items store the IMMUTABLE tax snapshot at purchase time, so a
 *   later rate change never re-prices a historical order. Legacy rows stay NULL
 *   and the invoice falls back to the old single-tax layout.
 *
 * Every add is guarded by hasColumn so it is safe to re-run on a live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('tax_classes', 'hsn_code')) {
                $table->string('hsn_code', 16)->nullable()->after('name');
            }
            if (!Schema::hasColumn('tax_classes', 'tax_category')) {
                // taxable | nil_rated | exempt | non_taxable | zero_rated
                $table->string('tax_category', 24)->default('taxable')->after('hsn_code');
            }
            if (!Schema::hasColumn('tax_classes', 'is_active')) {
                $table->boolean('is_active')->default(true)->index()->after('tax_category');
            }
            if (!Schema::hasColumn('tax_classes', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('tax_classes', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
            if (!Schema::hasColumn('tax_classes', 'description')) {
                $table->text('description')->nullable()->after('effective_to');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'hsn_code')) {
                $table->string('hsn_code', 16)->nullable()->after('is_taxable');
            }
            if (!Schema::hasColumn('products', 'tax_rate_id')) {
                $table->unsignedBigInteger('tax_rate_id')->nullable()->index()->after('hsn_code');
            }
            if (!Schema::hasColumn('products', 'tax_inclusive')) {
                // NULL = inherit the store-wide prices_include_tax preference.
                $table->boolean('tax_inclusive')->nullable()->after('tax_rate_id');
            }
            if (!Schema::hasColumn('products', 'tax_verified')) {
                $table->boolean('tax_verified')->default(false)->index()->after('tax_inclusive');
            }
        });

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (!Schema::hasColumn('categories', 'tax_rate_id')) {
                    $table->unsignedBigInteger('tax_rate_id')->nullable()->after('type_id');
                }
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'place_of_supply'        => fn () => $table->string('place_of_supply', 64)->nullable(),
                'place_of_supply_code'   => fn () => $table->string('place_of_supply_code', 8)->nullable(),
                'is_inter_state'         => fn () => $table->boolean('is_inter_state')->nullable(),
                'taxable_amount'         => fn () => $table->decimal('taxable_amount', 14, 2)->nullable(),
                'cgst_amount'            => fn () => $table->decimal('cgst_amount', 14, 2)->nullable(),
                'sgst_amount'            => fn () => $table->decimal('sgst_amount', 14, 2)->nullable(),
                'igst_amount'            => fn () => $table->decimal('igst_amount', 14, 2)->nullable(),
                'total_tax'              => fn () => $table->decimal('total_tax', 14, 2)->nullable(),
                'delivery_tax_treatment' => fn () => $table->string('delivery_tax_treatment', 24)->nullable(),
                'delivery_taxable'       => fn () => $table->decimal('delivery_taxable', 14, 2)->nullable(),
                'delivery_tax_amount'    => fn () => $table->decimal('delivery_tax_amount', 14, 2)->nullable(),
                'seller_gstin'           => fn () => $table->string('seller_gstin', 20)->nullable(),
                'seller_state'           => fn () => $table->string('seller_state', 64)->nullable(),
                'seller_state_code'      => fn () => $table->string('seller_state_code', 8)->nullable(),
            ] as $col => $add) {
                if (!Schema::hasColumn('orders', $col)) {
                    $add();
                }
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'hsn_code'      => fn () => $table->string('hsn_code', 16)->nullable(),
                'tax_category'  => fn () => $table->string('tax_category', 24)->nullable(),
                'tax_rate'      => fn () => $table->decimal('tax_rate', 5, 2)->nullable(),
                'tax_inclusive' => fn () => $table->boolean('tax_inclusive')->nullable(),
                'taxable_value' => fn () => $table->decimal('taxable_value', 14, 2)->nullable(),
                'cgst_rate'     => fn () => $table->decimal('cgst_rate', 5, 2)->nullable(),
                'sgst_rate'     => fn () => $table->decimal('sgst_rate', 5, 2)->nullable(),
                'igst_rate'     => fn () => $table->decimal('igst_rate', 5, 2)->nullable(),
                'cgst_amount'   => fn () => $table->decimal('cgst_amount', 14, 2)->nullable(),
                'sgst_amount'   => fn () => $table->decimal('sgst_amount', 14, 2)->nullable(),
                'igst_amount'   => fn () => $table->decimal('igst_amount', 14, 2)->nullable(),
                'tax_amount'    => fn () => $table->decimal('tax_amount', 14, 2)->nullable(),
            ] as $col => $add) {
                if (!Schema::hasColumn('order_items', $col)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally NON-destructive: the tax snapshot on historical orders must
        // survive a rollback. Columns are additive and nullable; leave them in place.
    }
};
