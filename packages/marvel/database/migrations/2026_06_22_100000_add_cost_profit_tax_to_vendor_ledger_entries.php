<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial completeness F1/F2 — record the vendor's cost of goods, resulting profit,
 * and the order's tax on each ledger entry (and aggregate them per settlement). All
 * NULLABLE/additive: existing rows and unknown-cost sales stay valid; the columns are
 * only populated when a vendor cost sheet exists. Tax is informational (the vendor never
 * receives it) so it does not change `amount`/`net_payable`.
 *   cost_value    = Σ vendor cost_price × qty for the order's lines (null if unknown)
 *   vendor_profit = vendor net earning − cost_value (null when cost unknown)
 *   tax_amount    = the parent order's sales_tax pro-rated to this entry
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_ledger_entries')) {
            Schema::table('vendor_ledger_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('vendor_ledger_entries', 'cost_value')) {
                    $table->decimal('cost_value', 14, 2)->nullable()->after('pg_fee');
                }
                if (!Schema::hasColumn('vendor_ledger_entries', 'vendor_profit')) {
                    $table->decimal('vendor_profit', 14, 2)->nullable()->after('cost_value');
                }
                if (!Schema::hasColumn('vendor_ledger_entries', 'tax_amount')) {
                    $table->decimal('tax_amount', 14, 2)->nullable()->after('vendor_profit');
                }
            });
        }

        if (Schema::hasTable('vendor_settlements')) {
            Schema::table('vendor_settlements', function (Blueprint $table) {
                if (!Schema::hasColumn('vendor_settlements', 'total_cost')) {
                    $table->decimal('total_cost', 16, 2)->nullable()->after('shipping_revenue');
                }
                if (!Schema::hasColumn('vendor_settlements', 'total_profit')) {
                    $table->decimal('total_profit', 16, 2)->nullable()->after('total_cost');
                }
                if (!Schema::hasColumn('vendor_settlements', 'total_tax')) {
                    $table->decimal('total_tax', 16, 2)->nullable()->after('total_profit');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_ledger_entries')) {
            Schema::table('vendor_ledger_entries', function (Blueprint $table) {
                foreach (['cost_value', 'vendor_profit', 'tax_amount'] as $col) {
                    if (Schema::hasColumn('vendor_ledger_entries', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('vendor_settlements')) {
            Schema::table('vendor_settlements', function (Blueprint $table) {
                foreach (['total_cost', 'total_profit', 'total_tax'] as $col) {
                    if (Schema::hasColumn('vendor_settlements', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
