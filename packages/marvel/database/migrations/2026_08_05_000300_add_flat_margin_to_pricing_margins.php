<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat-₹ margins alongside the existing percent margins.
 *
 * Selling price has always been MAX(vendor rate) × (1 + percent/100). The
 * business also wants "highest vendor price + ₹40" — a flat add. Each rule now
 * carries a type; existing rows keep type 'percent' untouched, so nothing
 * reprices on deploy. The formula itself moves into MarginResolver::apply so
 * the three former multiplication sites can never disagree.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pricing_margins')) {
            return;
        }
        Schema::table('pricing_margins', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_margins', 'margin_type')) {
                // 'percent' | 'flat'
                $table->string('margin_type', 10)->default('percent');
            }
            if (!Schema::hasColumn('pricing_margins', 'margin_flat')) {
                $table->decimal('margin_flat', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_margins')) {
            return;
        }
        Schema::table('pricing_margins', function (Blueprint $table) {
            foreach (['margin_type', 'margin_flat'] as $col) {
                if (Schema::hasColumn('pricing_margins', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
